<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Cloud\Providers;

use App\Enums\CloudFailure;
use App\Enums\CloudProviderType;
use App\Enums\CloudRefusal;
use App\Exceptions\CloudProviderException;
use App\Services\Deployment\LogSanitizer;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use LogicException;
use stdClass;
use Throwable;

abstract class HttpCloudApi
{
    public const CONNECT_SECONDS = 5;

    public const TIMEOUT_SECONDS = 10;

    public const MAX_PAGES = 20;

    public const MAX_WAIT_SECONDS = 60;

    protected const ID_PATTERN = '/\A[1-9]\d*\z/D';

    private const READ_ATTEMPTS = 3;

    private const READ_PAUSE_MILLISECONDS = 500;

    private const MAX_READ_PAUSE_SECONDS = 2;

    private const EPOCH_SECONDS = 1_000_000_000;

    private const RETRYABLE_STATUSES = [423, 429, 500, 502, 503, 504];

    private const DETAIL_LENGTH = 240;

    public function __construct(#[\SensitiveParameter] private readonly string $token) {}

    abstract public function type(): CloudProviderType;

    abstract protected function message(Response $response): ?string;

    public function resizeNeedsPowerOff(): bool
    {
        return $this->type()->resizeNeedsPowerOff();
    }

    public static function publicIpv4(mixed $ip): ?string
    {
        if (!is_string($ip)) {
            return null;
        }

        $accepted = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);

        return $accepted === false ? null : $ip;
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['type' => $this->type()->value];
    }

    protected function request(): PendingRequest
    {
        return Http::baseUrl($this->type()->apiUrl())
            ->withToken($this->token)
            ->acceptJson()
            ->asJson()
            ->withUserAgent('Coresmith/' . config('coresmith.version'))
            ->withOptions(['allow_redirects' => false])
            ->connectTimeout(self::CONNECT_SECONDS)
            ->timeout(self::TIMEOUT_SECONDS);
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, string>  $headers
     */
    protected function getJson(string $path, array $query = [], array $headers = []): Response
    {
        return $this->parsed($this->guard($this->send(fn(): Response => $this->reading($headers)->get($path, $query))));
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, string>  $headers
     */
    protected function getJsonOrNull(string $path, array $query = [], array $headers = []): ?Response
    {
        $response = $this->send(fn(): Response => $this->reading($headers)->get($path, $query));

        if ($response->status() === 404) {
            $this->requireJsonObject($response);

            return null;
        }

        return $this->parsed($this->guard($response));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function postJson(string $path, #[\SensitiveParameter] array $payload = []): Response
    {
        return $this->guard($this->send(fn(): Response => $this->request()->post($path, $this->body($payload))));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function patchJson(string $path, #[\SensitiveParameter] array $payload = []): Response
    {
        return $this->guard($this->send(fn(): Response => $this->request()->patch($path, $this->body($payload))));
    }

    protected function deleteOrGone(string $path): void
    {
        $response = $this->send(fn(): Response => $this->reading([])->delete($path));

        if ($response->status() === 404) {
            $this->requireJsonObject($response);

            return;
        }

        $this->guard($response);
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  Closure(Response): (array<string, mixed>|null)  $next
     * @param  array<string, string>  $headers
     * @return Collection<int, mixed>
     */
    protected function paginate(string $path, array $query, string $key, Closure $next, array $headers = []): Collection
    {
        $items = new Collection;

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $response = $this->getJson($path, $query, $headers);

            $items = $items->concat($this->listed($response, $key));
            $query = $next($response);

            if ($query === null) {
                break;
            }
        }

        return $items->values();
    }

    protected function detail(?string $raw): ?string
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        $text = str_replace($this->token, '[REDACTED]', $raw);
        $text = trim((string) preg_replace('/\s+/', ' ', $text));

        return Str::limit(app(LogSanitizer::class)->sanitize($text), self::DETAIL_LENGTH);
    }

    protected function classify(Response $response): ?CloudFailure
    {
        return null;
    }

    protected function refusal(Response $response): ?CloudRefusal
    {
        return null;
    }

    protected function segment(string $id): string
    {
        if (trim($id) === '' || $id === '.' || $id === '..') {
            throw new LogicException('A cloud id must name one resource.');
        }

        return rawurlencode($id);
    }

    /**
     * @return list<mixed>
     */
    protected function listed(Response $response, string $key): array
    {
        $items = $response->json($key);
        $declared = data_get(json_decode($response->body()), $key);

        return is_array($items) && is_array($declared) ? $items : throw $this->unusable();
    }

    protected function identifier(mixed $id): string
    {
        $text = is_int($id) ? (string) $id : $id;

        return is_string($text) && preg_match(static::ID_PATTERN, $text) === 1 ? $text : throw $this->unusable();
    }

    /**
     * @param  list<mixed>  $entries
     * @param  Closure(array<string, mixed>): bool  $matches
     * @return array<string, mixed>|null
     */
    protected function firstMatch(array $entries, Closure $matches): ?array
    {
        if ($entries === []) {
            return null;
        }

        foreach ($entries as $entry) {
            if (is_array($entry) && $matches($entry)) {
                return $entry;
            }
        }

        throw $this->unusable();
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    protected static function carries(array $entry, string $tag): bool
    {
        $tags = $entry['tags'] ?? null;

        return is_array($tags) && in_array($tag, $tags, true);
    }

    protected function unusable(): CloudProviderException
    {
        return new CloudProviderException(CloudFailure::Unavailable, $this->type(), 'answered without the data it was asked for');
    }

    protected static function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return list<string>
     */
    protected static function texts(mixed $values): array
    {
        return is_array($values)
            ? array_values(array_filter($values, fn(mixed $value): bool => self::text($value) !== null))
            : [];
    }

    protected static function hasPassed(mixed $time, bool $whenUnreadable): bool
    {
        $text = self::text($time);
        $at = $text === null ? false : date_create_immutable($text, new DateTimeZone('UTC'));

        return $at === false ? $whenUnreadable : $at->getTimestamp() <= now()->getTimestamp();
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    protected static function hasNumbers(array $entry, string ...$keys): bool
    {
        foreach ($keys as $key) {
            if (!is_numeric($entry[$key] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function reading(array $headers): PendingRequest
    {
        return $this->request()
            ->withHeaders($headers)
            ->retry(self::READ_ATTEMPTS, fn(int $attempt, Throwable $e): int => self::pause($e), fn(?Throwable $e): bool => $this->worthRetrying($e), throw: false);
    }

    private function worthRetrying(?Throwable $e): bool
    {
        return $e instanceof ConnectionException
            || ($e instanceof RequestException
                && in_array($e->response->status(), self::RETRYABLE_STATUSES, true)
                && ($this->classify($e->response)?->retryable() ?? true)
                && (self::wait($e->response) ?? 0) <= self::MAX_READ_PAUSE_SECONDS);
    }

    private static function pause(Throwable $e): int
    {
        $wait = $e instanceof RequestException ? self::wait($e->response) : null;

        return max(self::READ_PAUSE_MILLISECONDS, ($wait ?? 0) * 1000);
    }

    private static function wait(Response $response): ?int
    {
        $seconds = self::seconds($response->header('Retry-After'), false);

        if ($seconds === null && $response->status() === 429) {
            $seconds = self::seconds($response->header('RateLimit-Reset'), true) ?? self::seconds($response->header('X-RateLimit-Reset'), true);
        }

        return $seconds === null ? null : min(self::MAX_WAIT_SECONDS, max(1, $seconds));
    }

    private static function seconds(string $value, bool $orEpoch): ?int
    {
        $value = trim($value);

        if (preg_match('/\A\d{1,12}\z/D', $value) === 1) {
            $number = (int) $value;

            return $orEpoch && $number >= self::EPOCH_SECONDS ? $number - now()->getTimestamp() : $number;
        }

        $at = $orEpoch || $value === '' ? false : DateTimeImmutable::createFromFormat(DATE_RFC7231, $value, new DateTimeZone('UTC'));

        return $at === false ? null : $at->getTimestamp() - now()->getTimestamp();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function body(array $payload): array|object
    {
        return $payload === [] ? (object) [] : $payload;
    }

    private function send(#[\SensitiveParameter] Closure $call): Response
    {
        try {
            return $call();
        } catch (ConnectionException $e) {
            throw new CloudProviderException(CloudFailure::Unreachable, $this->type(), previous: new ConnectionException($e->getMessage()));
        }
    }

    private function guard(Response $response): Response
    {
        if ($response->failed() || $response->redirect()) {
            throw $this->failure($response);
        }

        return $response;
    }

    private function parsed(Response $response): Response
    {
        if (!is_array($response->json())) {
            throw new CloudProviderException(CloudFailure::Unavailable, $this->type(), 'answered without JSON');
        }

        return $response;
    }

    private function requireJsonObject(Response $response): void
    {
        if (!json_decode($response->body()) instanceof stdClass) {
            throw new CloudProviderException(CloudFailure::Unavailable, $this->type(), 'answered without JSON', $response->status());
        }
    }

    private function failure(Response $response): CloudProviderException
    {
        $status = $response->status();

        if ($response->redirect()) {
            return new CloudProviderException(CloudFailure::Refused, $this->type(), 'answered with a redirect', $status);
        }

        $failure = $this->classify($response) ?? match (true) {
            $status === 401 => CloudFailure::TokenRejected,
            $status === 403 => CloudFailure::Forbidden,
            $status === 404 => CloudFailure::NotFound,
            $status === 423 => CloudFailure::Busy,
            $status === 429 => CloudFailure::RateLimited,
            $status >= 500 => CloudFailure::Unavailable,
            default => CloudFailure::Refused,
        };

        return new CloudProviderException(
            $failure,
            $this->type(),
            $this->detail($this->message($response)),
            $status,
            retryAfter: $failure->retryable() ? self::wait($response) : null,
            refusal: $failure === CloudFailure::Refused ? $this->refusal($response) : null,
        );
    }
}
