<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Git\GitHub;

use App\Exceptions\GitHubInstallationException;
use App\Exceptions\GitProviderException;
use App\Services\Git\RefusalReason;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

final class GitHubAppApi
{
    public const API = 'https://api.github.com';

    public const WEB = 'https://github.com';

    private const ACCEPT = 'application/vnd.github+json';

    private const VERSION = '2022-11-28';

    private const TIMEOUT = 20;

    public const NO_ANSWER = 'GitHub did not answer.';

    public function __construct(private readonly AppJwt $jwt) {}

    /**
     * @return array{app_id: string, slug: string, name: string, client_id: string, client_secret: string, webhook_secret: ?string, pem: string, owner_login: string, owner_type: string, html_url: string, permissions: array<string, string>, events: list<string>}
     */
    public function convertManifest(#[\SensitiveParameter] string $code): array
    {
        $url = self::API . '/app-manifests/' . $this->manifestCode($code) . '/conversions';
        $app = (array) $this->guard($this->call(fn(): Response => $this->http()->send('POST', $url)))->json();

        foreach (['id', 'slug', 'client_id', 'client_secret', 'pem'] as $key) {
            if (!is_scalar($app[$key] ?? null) || (string) $app[$key] === '') {
                throw new GitProviderException('GitHub did not send the app\'s credentials.');
            }
        }

        return [
            'app_id' => (string) $app['id'],
            'slug' => (string) $app['slug'],
            'name' => (string) ($app['name'] ?? ''),
            'client_id' => (string) $app['client_id'],
            'client_secret' => (string) $app['client_secret'],
            'webhook_secret' => isset($app['webhook_secret']) && $app['webhook_secret'] !== '' ? (string) $app['webhook_secret'] : null,
            'pem' => (string) $app['pem'],
            'owner_login' => (string) ($app['owner']['login'] ?? ''),
            'owner_type' => (string) ($app['owner']['type'] ?? ''),
            'html_url' => (string) ($app['html_url'] ?? ''),
            'permissions' => (array) ($app['permissions'] ?? []),
            'events' => array_values(array_map('strval', (array) ($app['events'] ?? []))),
        ];
    }

    public function hookUrl(#[\SensitiveParameter] GitHubAppCredentials $app): ?string
    {
        $request = $this->asApp($app);
        $url = $this->guard($this->call(fn(): Response => $request->get(self::API . '/app/hook/config')))->json('url');

        return is_string($url) && $url !== '' ? $url : null;
    }

    public function updateHook(#[\SensitiveParameter] GitHubAppCredentials $app, string $url, #[\SensitiveParameter] string $secret): void
    {
        $request = $this->asApp($app);

        $this->guard($this->call(fn(): Response => $request->patch(self::API . '/app/hook/config', [
            'url' => $url,
            'content_type' => 'json',
            'secret' => $secret,
            'insecure_ssl' => '0',
        ])));
    }

    /**
     * @return array{status_code: int, status: string, delivered_at: CarbonImmutable, event: string}|null
     */
    public function latestDelivery(#[\SensitiveParameter] GitHubAppCredentials $app): ?array
    {
        $request = $this->asApp($app);
        $deliveries = $this->guard($this->call(fn(): Response => $request->get(self::API . '/app/hook/deliveries', ['per_page' => 10])))->json();
        $newest = null;

        foreach ((array) $deliveries as $delivery) {
            if (!is_array($delivery) || !is_string($delivery['delivered_at'] ?? null)) {
                continue;
            }

            $at = CarbonImmutable::parse($delivery['delivered_at']);

            if ($newest === null || $at->greaterThan($newest['delivered_at'])) {
                $newest = [
                    'status_code' => (int) ($delivery['status_code'] ?? 0),
                    'status' => (string) ($delivery['status'] ?? ''),
                    'delivered_at' => $at,
                    'event' => (string) ($delivery['event'] ?? ''),
                ];
            }
        }

        return $newest;
    }

    public function exchangeUserCode(#[\SensitiveParameter] GitHubAppCredentials $app, #[\SensitiveParameter] string $code, string $redirectUri): string
    {
        $response = $this->call(fn(): Response => Http::asForm()->acceptJson()->timeout(self::TIMEOUT)->post(self::WEB . '/login/oauth/access_token', [
            'client_id' => $app->clientId,
            'client_secret' => $app->clientSecret,
            'code' => $code,
            'redirect_uri' => $redirectUri,
        ]));
        $token = $response->json('access_token');

        if ($response->json('error') !== null || !$response->successful()) {
            throw new GitProviderException(RefusalReason::for($response));
        }

        if (!is_string($token) || $token === '') {
            throw new GitProviderException('GitHub did not send an access token.');
        }

        return $token;
    }

    /**
     * @return list<array{id: string, account: string, account_type: string, repository_selection: string, suspended_at: ?string}>
     */
    public function userInstallations(#[\SensitiveParameter] string $userToken): array
    {
        $response = $this->guard($this->call(fn(): Response => $this->http()->withToken($userToken)->get(self::API . '/user/installations', ['per_page' => 100])));
        $installations = array_filter((array) $response->json('installations', []), 'is_array');

        return array_values(array_map(fn(array $installation): array => [
            'id' => (string) $installation['id'],
            'account' => (string) ($installation['account']['login'] ?? $installation['account']['slug'] ?? ''),
            'account_type' => (string) ($installation['account']['type'] ?? ''),
            'repository_selection' => (string) ($installation['repository_selection'] ?? ''),
            'suspended_at' => isset($installation['suspended_at']) ? (string) $installation['suspended_at'] : null,
        ], $installations));
    }

    public function userLogin(#[\SensitiveParameter] string $userToken): string
    {
        $login = $this->guard($this->call(fn(): Response => $this->http()->withToken($userToken)->get(self::API . '/user')))->json('login');

        if (!is_string($login) || $login === '') {
            throw new GitProviderException('GitHub did not say which account is signed in.');
        }

        return $login;
    }

    public function userRepositoryCount(#[\SensitiveParameter] string $userToken, string $installationId): int
    {
        $url = self::API . '/user/installations/' . $this->installation($installationId) . '/repositories';

        return $this->total($this->guard($this->call(fn(): Response => $this->http()->withToken($userToken)->get($url, ['per_page' => 1]))));
    }

    public function revokeUserToken(#[\SensitiveParameter] GitHubAppCredentials $app, #[\SensitiveParameter] string $userToken): void
    {
        $this->call(fn(): Response => $this->http()
            ->withBasicAuth($app->clientId, $app->clientSecret)
            ->delete(self::API . '/applications/' . rawurlencode($app->clientId) . '/token', ['access_token' => $userToken]));
    }

    /**
     * @param  array<string, mixed>  $scope
     * @return array{token: string, expires_at: CarbonImmutable}
     */
    public function mintToken(#[\SensitiveParameter] GitHubAppCredentials $app, string $installationId, array $scope = []): array
    {
        $url = self::API . '/app/installations/' . $this->installation($installationId) . '/access_tokens';
        $request = $this->asApp($app);

        try {
            $response = $scope === [] ? $request->send('POST', $url) : $request->post($url, $scope);
        } catch (ConnectionException) {
            throw new GitHubInstallationException(self::NO_ANSWER, GitHubInstallationException::UNREACHABLE);
        }

        if (!$response->successful()) {
            throw new GitHubInstallationException('GitHub refused to issue an installation token: ' . RefusalReason::for($response), $this->refusal($response));
        }

        $token = $response->json('token');
        $expires = $response->json('expires_at');

        if (!is_string($token) || $token === '' || !is_string($expires) || $expires === '') {
            throw new GitHubInstallationException('GitHub did not send an installation token.', GitHubInstallationException::UNREACHABLE);
        }

        return ['token' => $token, 'expires_at' => CarbonImmutable::parse($expires)];
    }

    public function installationRepositoryCount(#[\SensitiveParameter] string $installationToken): int
    {
        return $this->total($this->guard($this->call(fn(): Response => $this->http()->withToken($installationToken)->get(self::API . '/installation/repositories', ['per_page' => 1]))));
    }

    public function revokeToken(#[\SensitiveParameter] string $token): void
    {
        $this->call(fn(): Response => $this->http()->withToken($token)->delete(self::API . '/installation/token'));
    }

    public function http(): PendingRequest
    {
        return Http::withHeaders(['Accept' => self::ACCEPT, 'X-GitHub-Api-Version' => self::VERSION])->timeout(self::TIMEOUT);
    }

    public function call(Closure $send): Response
    {
        try {
            return $send();
        } catch (ConnectionException) {
            throw new GitProviderException(self::NO_ANSWER);
        }
    }

    private function asApp(#[\SensitiveParameter] GitHubAppCredentials $app): PendingRequest
    {
        return $this->http()->withToken($this->jwt->for($app));
    }

    private function guard(Response $response): Response
    {
        if (!$response->successful()) {
            throw new GitProviderException(RefusalReason::for($response));
        }

        return $response;
    }

    private function total(Response $response): int
    {
        $total = $response->json('total_count');

        if (!is_int($total) || $total < 0) {
            throw new GitProviderException('GitHub did not send a repository count.');
        }

        return $total;
    }

    private function refusal(Response $response): string
    {
        return match (true) {
            $response->status() === 404 => GitHubInstallationException::UNINSTALLED,
            $response->status() === 403 && !$this->rateLimited($response) => GitHubInstallationException::SUSPENDED,
            $response->status() === 422 => GitHubInstallationException::REPOSITORY,
            default => GitHubInstallationException::UNREACHABLE,
        };
    }

    private function rateLimited(Response $response): bool
    {
        return $response->header('Retry-After') !== ''
            || $response->header('X-RateLimit-Remaining') === '0'
            || stripos(RefusalReason::for($response), 'rate limit') !== false;
    }

    private function manifestCode(#[\SensitiveParameter] string $code): string
    {
        if (preg_match('/^[A-Za-z0-9_-]{1,100}$/D', $code) !== 1) {
            throw new GitProviderException('That is not a GitHub manifest code.');
        }

        return $code;
    }

    private function installation(string $installationId): string
    {
        if (preg_match('/^[0-9]{1,20}$/D', $installationId) !== 1) {
            throw new GitProviderException("\"{$installationId}\" is not a valid installation id.");
        }

        return $installationId;
    }
}
