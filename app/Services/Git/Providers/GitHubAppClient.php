<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Git\Providers;

use App\Exceptions\GitProviderException;
use App\Models\SourceProvider;
use App\Services\Git\Contracts\GitProvider;
use App\Services\Git\Repository;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use phpseclib3\Crypt\RSA;

final class GitHubAppClient implements GitProvider
{
    public const API = 'https://api.github.com';

    public const TOKEN_SKEW_SECONDS = 300;

    public function __construct(private readonly SourceProvider $provider) {}

    public function listRepositories(): Collection
    {
        $repositories = collect();
        $page = 1;

        do {
            $body = $this->get('/installation/repositories', ['per_page' => 100, 'page' => $page])->json();
            $batch = (array) ($body['repositories'] ?? []);

            foreach ($batch as $repository) {
                $repositories->push(new Repository(
                    fullName: (string) $repository['full_name'],
                    sshUrl: (string) $repository['ssh_url'],
                    defaultBranch: (string) ($repository['default_branch'] ?? 'main'),
                    private: (bool) ($repository['private'] ?? true),
                ));
            }

            $page++;
        } while ($batch !== [] && $repositories->count() < (int) ($body['total_count'] ?? 0));

        return $repositories;
    }

    public function createWebhook(string $repository, string $url, string $secret): string
    {
        $response = $this->post("/repos/{$this->path($repository)}/hooks", [
            'name' => 'web',
            'active' => true,
            'events' => ['push'],
            'config' => ['url' => $url, 'content_type' => 'json', 'secret' => $secret, 'insecure_ssl' => '0'],
        ]);

        return (string) $response->json('id');
    }

    public function deleteWebhook(string $repository, string $hookId): void
    {
        $this->request()->delete(self::API . "/repos/{$this->path($repository)}/hooks/" . rawurlencode($hookId));
    }

    public function createDeployKey(string $repository, string $publicKey, string $title): string
    {
        $response = $this->post("/repos/{$this->path($repository)}/keys", [
            'title' => $title,
            'key' => $publicKey,
            'read_only' => true,
        ]);

        return (string) $response->json('id');
    }

    public function deleteDeployKey(string $repository, string $keyId): void
    {
        $this->request()->delete(self::API . "/repos/{$this->path($repository)}/keys/" . rawurlencode($keyId));
    }

    public function latestCommit(string $repository, string $branch): array
    {
        $commit = $this->get("/repos/{$this->path($repository)}/commits/" . rawurlencode($branch))->json();

        return [
            'sha' => (string) ($commit['sha'] ?? ''),
            'message' => (string) ($commit['commit']['message'] ?? ''),
            'author' => (string) ($commit['commit']['author']['name'] ?? ''),
        ];
    }

    public function updateCommitStatus(string $repository, string $sha, string $state, string $targetUrl, string $description): void
    {
        $this->post("/repos/{$this->path($repository)}/statuses/" . rawurlencode($sha), [
            'state' => $state,
            'target_url' => $targetUrl,
            'description' => $description,
            'context' => 'coresmith/deploy',
        ]);
    }

    public function revoke(): void
    {
        $this->request()->delete(self::API . '/installation/token');
    }

    public function token(): string
    {
        $expiry = $this->provider->token_expires_at;

        if ($this->provider->access_token !== null && $expiry !== null && $expiry->subSeconds(self::TOKEN_SKEW_SECONDS)->isFuture()) {
            return (string) $this->provider->access_token;
        }

        return $this->mintInstallationToken();
    }

    private function mintInstallationToken(): string
    {
        $installation = $this->provider->installation_id;

        if ($installation === null || $installation === '') {
            throw new GitProviderException('This GitHub connection has no installation to mint a token for. Reconnect it from Settings → Source providers.');
        }

        $response = Http::withToken($this->jwt())
            ->withHeaders(['Accept' => 'application/vnd.github+json'])
            ->post(self::API . '/app/installations/' . rawurlencode($installation) . '/access_tokens');

        if ($response->failed()) {
            throw new GitProviderException('GitHub refused to issue an installation token: ' . $this->reason($response));
        }

        $token = (string) $response->json('token');

        $this->provider->forceFill([
            'access_token' => $token,
            'token_expires_at' => Carbon::parse((string) $response->json('expires_at')),
        ])->save();

        return $token;
    }

    private function jwt(): string
    {
        $key = (string) $this->provider->app_private_key;
        $appId = (string) $this->provider->app_id;

        if ($key === '' || $appId === '') {
            throw new GitProviderException('This GitHub connection is missing its App credentials.');
        }

        $header = $this->base64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $claims = $this->base64Url(json_encode([
            'iat' => time() - 60,
            'exp' => time() + 540,
            'iss' => $appId,
        ], JSON_THROW_ON_ERROR));

        $signature = RSA::loadPrivateKey($key)
            ->withHash('sha256')
            ->withPadding(RSA::SIGNATURE_PKCS1)
            ->sign($header . '.' . $claims);

        return $header . '.' . $claims . '.' . $this->base64Url($signature);
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function path(string $repository): string
    {
        if (!preg_match('/^[A-Za-z0-9._-]+\/[A-Za-z0-9._-]+$/', $repository)) {
            throw new GitProviderException("\"{$repository}\" is not a valid owner/repo name.");
        }

        return $repository;
    }

    private function request(): PendingRequest
    {
        return Http::withToken($this->token())
            ->withHeaders(['Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2022-11-28'])
            ->timeout(20)
            ->retry(2, 200, throw: false);
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function get(string $path, array $query = []): Response
    {
        return $this->guard($this->request()->get(self::API . $path, $query));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function post(string $path, array $payload): Response
    {
        return $this->guard($this->request()->post(self::API . $path, $payload));
    }

    private function guard(Response $response): Response
    {
        if ($response->failed()) {
            throw new GitProviderException('GitHub rejected the request: ' . $this->reason($response));
        }

        return $response;
    }

    private function reason(Response $response): string
    {
        $message = (string) ($response->json('message') ?? '');

        return $message !== '' ? $message : 'HTTP ' . $response->status();
    }
}
