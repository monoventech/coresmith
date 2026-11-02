<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Git\Providers;

use App\Exceptions\GitProviderException;
use App\Models\SourceProvider;
use App\Services\Git\Contracts\GitProvider;
use App\Services\Git\GitHub\GitHubAppApi;
use App\Services\Git\GitHub\GitHubAppCredentials;
use App\Services\Git\RefusalReason;
use App\Services\Git\Repository;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

final class GitHubAppClient implements GitProvider
{
    public const TOKEN_SKEW_SECONDS = 300;

    public const TOKEN_LOCK_SECONDS = 30;

    public const TOKEN_WAIT_SECONDS = 5;

    public function __construct(
        private readonly SourceProvider $provider,
        #[\SensitiveParameter] private readonly GitHubAppCredentials $app,
        private readonly GitHubAppApi $api,
    ) {}

    public static function tokenLock(SourceProvider $provider): string
    {
        return 'github:token:' . config('app.env') . ':' . $provider->getKey();
    }

    public static function isFullName(string $fullName): bool
    {
        return preg_match('/^[A-Za-z0-9._-]+\/[A-Za-z0-9._-]+$/D', $fullName) === 1 && array_intersect(explode('/', $fullName), ['.', '..']) === [];
    }

    public function listRepositories(): Collection
    {
        return collect($this->installationRepositories()['repositories'])->map(fn(array $repository): Repository => new Repository(
            fullName: $repository['full_name'],
            sshUrl: "git@github.com:{$repository['full_name']}.git",
            defaultBranch: $repository['default_branch'],
            private: $repository['private'],
        ));
    }

    /**
     * @return array{total: int, repositories: list<array{id: string, full_name: string, private: bool, default_branch: string, archived: bool, pushed_at: ?string}>}
     */
    public function installationRepositories(int $maxPages = 10): array
    {
        $repositories = [];
        $total = 0;

        for ($page = 1; $page <= $maxPages; $page++) {
            $body = (array) $this->get('/installation/repositories', ['per_page' => 100, 'page' => $page])->json();
            $batch = (array) ($body['repositories'] ?? []);
            $total = (int) ($body['total_count'] ?? 0);

            foreach ($batch as $repository) {
                $repositories[] = [
                    'id' => (string) $repository['id'],
                    'full_name' => (string) $repository['full_name'],
                    'private' => (bool) ($repository['private'] ?? true),
                    'default_branch' => (string) ($repository['default_branch'] ?? 'main'),
                    'archived' => (bool) ($repository['archived'] ?? false),
                    'pushed_at' => isset($repository['pushed_at']) ? (string) $repository['pushed_at'] : null,
                ];
            }

            if ($batch === [] || count($repositories) >= $total) {
                break;
            }
        }

        usort($repositories, fn(array $a, array $b): int => ($b['pushed_at'] ?? '') <=> ($a['pushed_at'] ?? ''));

        return ['total' => $total, 'repositories' => $repositories];
    }

    /**
     * @return list<string>
     */
    public function branches(string $fullName): array
    {
        $branches = array_filter((array) $this->get("/repos/{$this->path($fullName)}/branches", ['per_page' => 100])->json(), fn($branch): bool => is_array($branch) && isset($branch['name']));

        return array_values(array_map(fn(array $branch): string => (string) $branch['name'], $branches));
    }

    public function branchHead(string $fullName, string $branch, ?string $knownSha): ?string
    {
        $path = '/repos/' . $this->path($fullName) . '/commits/heads/' . $this->ref($branch);
        $headers = ['Accept' => 'application/vnd.github.sha'];

        if ($knownSha !== null && preg_match('/^[0-9a-f]{40}$/D', $knownSha) === 1) {
            $headers['If-None-Match'] = '"' . $knownSha . '"';
        }

        $response = $this->send(fn(PendingRequest $request): Response => $request->replaceHeaders($headers)->get(GitHubAppApi::API . $path), retry: false);

        if ($response->status() === 304) {
            return null;
        }

        if (in_array($response->status(), [404, 422], true)) {
            throw new GitProviderException("GitHub has no branch {$branch} in {$fullName} that this app can see.");
        }

        if (!$response->successful()) {
            throw new GitProviderException('GitHub refused the check. ' . RefusalReason::for($response));
        }

        $sha = trim($response->body());

        if (preg_match('/^[0-9a-f]{40}$/D', $sha) !== 1) {
            throw new GitProviderException(GitHubAppApi::NO_ANSWER);
        }

        return $sha;
    }

    /**
     * @return array{permission: string, user_id: ?string}|null
     */
    public function repositoryPermission(string $fullName, string $login): ?array
    {
        $path = '/repos/' . $this->path($fullName) . '/collaborators/' . rawurlencode($login) . '/permission';
        $response = $this->send(fn(PendingRequest $request): Response => $request->get(GitHubAppApi::API . $path), retry: false);

        if ($response->status() === 404) {
            return null;
        }

        $permission = $this->guard($response)->json('permission');
        $userId = $response->json('user.id');

        if (!is_string($permission)) {
            throw new GitProviderException(GitHubAppApi::NO_ANSWER);
        }

        return ['permission' => $permission, 'user_id' => is_int($userId) ? (string) $userId : null];
    }

    public function loginOfUser(string $userId): ?string
    {
        if (preg_match('/^[0-9]{1,20}$/D', $userId) !== 1) {
            throw new GitProviderException("\"{$userId}\" is not a valid GitHub user id.");
        }

        $response = $this->send(fn(PendingRequest $request): Response => $request->get(GitHubAppApi::API . '/user/' . $userId), retry: false);

        if ($response->status() === 404) {
            return null;
        }

        $login = $this->guard($response)->json('login');

        if (!is_string($login) || $login === '') {
            throw new GitProviderException(GitHubAppApi::NO_ANSWER);
        }

        return $login;
    }

    public function cloneToken(string $repositoryId): string
    {
        if (preg_match('/^[0-9]{1,18}$/D', $repositoryId) !== 1) {
            throw new GitProviderException("\"{$repositoryId}\" is not a valid repository id.");
        }

        return $this->api->mintToken($this->app, $this->installation(), [
            'repository_ids' => [(int) $repositoryId],
            'permissions' => ['contents' => 'read'],
        ])['token'];
    }

    public function postDeployStatus(string $fullName, string $sha, string $state, string $targetUrl, string $description, string $context): void
    {
        $this->post("/repos/{$this->path($fullName)}/statuses/" . rawurlencode($sha), [
            'state' => $state,
            'target_url' => $targetUrl,
            'description' => $description,
            'context' => $context,
        ]);
    }

    public function createWebhook(string $repository, string $url, #[\SensitiveParameter] string $secret): string
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
        $this->delete("/repos/{$this->path($repository)}/hooks/" . rawurlencode($hookId));
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
        $this->delete("/repos/{$this->path($repository)}/keys/" . rawurlencode($keyId));
    }

    public function latestCommit(string $repository, string $branch): array
    {
        return ['sha' => (string) $this->branchHead($repository, $branch, null), 'message' => '', 'author' => ''];
    }

    public function updateCommitStatus(string $repository, string $sha, string $state, string $targetUrl, string $description): void
    {
        $this->postDeployStatus($repository, $sha, $state, $targetUrl, $description, 'coresmith/deploy');
    }

    public function revoke(): void
    {
        $token = $this->cachedToken();

        if ($token === null) {
            return;
        }

        $this->api->revokeToken($token);
    }

    public function token(): string
    {
        $cached = $this->cachedToken();

        if ($cached !== null) {
            return $cached;
        }

        $lock = Cache::lock(self::tokenLock($this->provider), self::TOKEN_LOCK_SECONDS);

        try {
            $lock->block(self::TOKEN_WAIT_SECONDS);
        } catch (LockTimeoutException) {
            return $this->mintInstallationToken();
        }

        try {
            $this->provider->refresh();

            return $this->cachedToken() ?? $this->mintInstallationToken();
        } finally {
            $lock->release();
        }
    }

    private function cachedToken(): ?string
    {
        $expiry = $this->provider->token_expires_at;

        if ($this->provider->access_token !== null && $expiry !== null && $expiry->subSeconds(self::TOKEN_SKEW_SECONDS)->isFuture()) {
            return (string) $this->provider->access_token;
        }

        return null;
    }

    private function mintInstallationToken(): string
    {
        $minted = $this->api->mintToken($this->app, $this->installation());

        $this->provider->forceFill([
            'access_token' => $minted['token'],
            'token_expires_at' => $minted['expires_at'],
        ])->save();

        return $minted['token'];
    }

    private function installation(): string
    {
        $installation = (string) $this->provider->installation_id;

        if ($installation === '') {
            throw new GitProviderException('This GitHub connection has no installation to mint a token for. Reconnect it from Settings → Source providers.');
        }

        return $installation;
    }

    private function path(string $repository): string
    {
        if (!self::isFullName($repository)) {
            throw new GitProviderException("\"{$repository}\" is not a valid owner/repo name.");
        }

        return $repository;
    }

    private function ref(string $branch): string
    {
        $segments = explode('/', $branch);

        if (array_intersect($segments, ['', '.', '..']) !== []) {
            throw new GitProviderException("\"{$branch}\" is not a branch name Git accepts.");
        }

        return implode('/', array_map('rawurlencode', $segments));
    }

    private function send(Closure $call, bool $retry = true): Response
    {
        $token = $this->token();
        $request = $this->api->http()->withToken($token);
        $response = $this->api->call(fn(): Response => $call($retry ? $request->retry(2, 200, throw: false) : $request));

        if ($response->status() === 401) {
            $this->forget();
        }

        return $response;
    }

    private function forget(): void
    {
        $stored = $this->provider->getRawOriginal('access_token');

        if ($stored === null) {
            return;
        }

        SourceProvider::withTrashed()
            ->whereKey($this->provider->getKey())
            ->where('access_token', $stored)
            ->update(['access_token' => null, 'token_expires_at' => null]);

        $this->provider->forceFill(['access_token' => null, 'token_expires_at' => null])->syncOriginalAttributes(['access_token', 'token_expires_at']);
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function get(string $path, array $query = []): Response
    {
        return $this->guard($this->send(fn(PendingRequest $request): Response => $request->get(GitHubAppApi::API . $path, $query)));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function post(string $path, #[\SensitiveParameter] array $payload): Response
    {
        return $this->guard($this->send(fn(PendingRequest $request): Response => $request->post(GitHubAppApi::API . $path, $payload), retry: false));
    }

    private function delete(string $path): void
    {
        $this->send(fn(PendingRequest $request): Response => $request->delete(GitHubAppApi::API . $path));
    }

    private function guard(Response $response): Response
    {
        if ($response->failed()) {
            throw new GitProviderException('GitHub rejected the request: ' . RefusalReason::for($response), $response->status());
        }

        return $response;
    }
}
