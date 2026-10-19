<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Git\Providers;

use App\Exceptions\GitProviderException;
use App\Models\SourceProvider;
use App\Services\Git\Contracts\GitProvider;
use App\Services\Git\RefusalReason;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

abstract class OAuthGitClient implements GitProvider
{
    public const TOKEN_SKEW_SECONDS = 300;

    public const NO_REDIRECTS = ['allow_redirects' => false];

    public function __construct(protected readonly SourceProvider $provider) {}

    abstract protected function apiRoot(): string;

    abstract protected function tokenEndpoint(): string;

    abstract protected function credentials(): array;

    abstract protected function providerName(): string;

    public function token(): string
    {
        $expiry = $this->provider->token_expires_at;

        if ($expiry === null || $expiry->subSeconds(self::TOKEN_SKEW_SECONDS)->isFuture()) {
            return $this->currentToken();
        }

        return $this->refresh();
    }

    protected function currentToken(): string
    {
        $token = (string) $this->provider->access_token;

        if ($token === '') {
            throw new GitProviderException("This {$this->providerName()} connection has no access token. Reconnect it from Settings → Source providers.");
        }

        return $token;
    }

    protected function refresh(): string
    {
        $refresh = (string) $this->provider->refresh_token;

        if ($refresh === '') {
            throw new GitProviderException("This {$this->providerName()} connection has expired and cannot be refreshed. Reconnect it from Settings → Source providers.");
        }

        $response = Http::asForm()->withOptions(self::NO_REDIRECTS)->timeout(20)->post($this->tokenEndpoint(), array_merge($this->credentials(), [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refresh,
        ]));

        if ($response->failed()) {
            throw new GitProviderException("{$this->providerName()} refused to refresh the connection: " . RefusalReason::for($response));
        }

        $token = (string) $response->json('access_token');

        $this->provider->forceFill([
            'access_token' => $token,
            'refresh_token' => (string) ($response->json('refresh_token') ?? $refresh),
            'token_expires_at' => now()->addSeconds((int) ($response->json('expires_in') ?? 7200)),
        ])->save();

        return $token;
    }

    protected function request(): PendingRequest
    {
        return Http::withToken($this->token())
            ->acceptJson()
            ->withOptions(self::NO_REDIRECTS)
            ->timeout(20)
            ->retry(2, 200, throw: false);
    }

    /**
     * @param  array<string, mixed>  $query
     */
    protected function get(string $path, array $query = []): Response
    {
        return $this->guard($this->request()->get($this->apiRoot() . $path, $query));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function post(string $path, array $payload): Response
    {
        return $this->guard($this->request()->post($this->apiRoot() . $path, $payload));
    }

    protected function delete(string $path): void
    {
        $this->request()->delete($this->apiRoot() . $path);
    }

    protected function guard(Response $response): Response
    {
        if ($response->failed()) {
            throw new GitProviderException("{$this->providerName()} rejected the request: " . RefusalReason::for($response));
        }

        return $response;
    }
}
