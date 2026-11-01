<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Updates;

use App\Services\Deployment\LogSanitizer;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use JsonException;
use stdClass;
use Throwable;

final class UpdateChecker
{
    public const MAX_BYTES = 65536;

    public const OFF = 'Update checks are off on this install.';

    public function __construct(
        private readonly UpdateState $state,
        private readonly LogSanitizer $sanitizer,
    ) {}

    public static function dailyAt(): string
    {
        return sprintf('03:%02d', crc32((string) config('app.key')) % 60);
    }

    public function enabled(): bool
    {
        return (bool) config('coresmith.updates.enabled') && !(bool) config('coresmith.updates.check_disabled');
    }

    public function latest(): ?UpdateCheck
    {
        return $this->state->readCheck();
    }

    public function check(): UpdateCheck
    {
        $url = (string) config('coresmith.updates.check_url');
        $previous = $this->state->readCheck();
        $now = CarbonImmutable::now();

        if (!$this->enabled()) {
            return new UpdateCheck($previous->checkedAt ?? $now, $previous->url ?? $this->sanitizer->sanitize($url), $previous?->feed, self::OFF, null);
        }

        try {
            $feed = $this->fetch($url);
            $check = new UpdateCheck($now, $this->sanitizer->sanitize($url), $feed, null, null);
        } catch (FeedUnavailable $e) {
            $check = new UpdateCheck($now, $this->sanitizer->sanitize($url), $previous?->feed, $e->getMessage(), $now);
        }

        $this->state->writeCheck($check);

        return $check;
    }

    private function fetch(string $url): FeedDocument
    {
        if (!in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['https', 'http'], true)) {
            throw new FeedUnavailable('The update check URL must start with https:// or http://.');
        }

        $host = (string) (parse_url($url, PHP_URL_HOST) ?: $url);

        try {
            $response = Http::timeout(10)
                ->connectTimeout(5)
                ->withUserAgent('Coresmith')
                ->acceptJson()
                ->withOptions(['allow_redirects' => ['max' => 3, 'protocols' => ['https', 'http']]])
                ->get($url);
        } catch (Throwable) {
            throw new FeedUnavailable("Coresmith couldn't reach the release feed at {$host}. Check this server can make outbound HTTPS requests, then try again.");
        }

        if ($response->status() !== 200) {
            throw new FeedUnavailable("The release feed at {$host} answered {$response->status()}. Try again later.");
        }

        try {
            return FeedDocument::fromArray($this->decode($response));
        } catch (InvalidFeed $e) {
            throw new FeedUnavailable("The release feed at {$host} sent something Coresmith doesn't understand: {$e->getMessage()}");
        }
    }

    /**
     * @return array<array-key, mixed>
     */
    private function decode(Response $response): array
    {
        $body = $response->toPsrResponse()->getBody();

        if ($body->isSeekable()) {
            $body->rewind();
        }

        $content = '';

        while (!$body->eof() && strlen($content) <= self::MAX_BYTES) {
            $chunk = $body->read(self::MAX_BYTES + 1 - strlen($content));

            if ($chunk === '') {
                break;
            }

            $content .= $chunk;
        }

        if (strlen($content) > self::MAX_BYTES) {
            throw new InvalidFeed('the answer is larger than 64 KB.');
        }

        try {
            $decoded = json_decode($content, false, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidFeed("the answer isn't JSON.");
        }

        if (!$decoded instanceof stdClass) {
            throw new InvalidFeed("the answer isn't a JSON object.");
        }

        return (array) json_decode($content, true, 64, JSON_THROW_ON_ERROR);
    }
}
