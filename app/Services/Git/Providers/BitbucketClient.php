<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Git\Providers;

use App\Exceptions\GitProviderException;
use App\Services\Git\Repository;
use Illuminate\Support\Collection;

final class BitbucketClient extends OAuthGitClient
{
    public const API = 'https://api.bitbucket.org/2.0';

    public function listRepositories(): Collection
    {
        $repositories = collect();
        $path = '/repositories?role=member&pagelen=100';

        while ($path !== null) {
            $body = $this->get($path)->json();

            foreach ((array) ($body['values'] ?? []) as $repository) {
                $repositories->push(new Repository(
                    fullName: (string) $repository['full_name'],
                    sshUrl: $this->sshUrl($repository),
                    defaultBranch: (string) ($repository['mainbranch']['name'] ?? 'main'),
                    private: (bool) ($repository['is_private'] ?? true),
                ));
            }

            $next = (string) ($body['next'] ?? '');
            $path = $next !== '' ? str_replace(self::API, '', $next) : null;
        }

        return $repositories;
    }

    public function createWebhook(string $repository, string $url, string $secret): string
    {
        $response = $this->post("/repositories/{$this->path($repository)}/hooks", [
            'description' => 'Coresmith',
            'url' => $url,
            'active' => true,
            'secret' => $secret,
            'events' => ['repo:push'],
        ]);

        return (string) $response->json('uuid');
    }

    public function deleteWebhook(string $repository, string $hookId): void
    {
        $this->delete("/repositories/{$this->path($repository)}/hooks/".rawurlencode($hookId));
    }

    public function createDeployKey(string $repository, string $publicKey, string $title): string
    {
        $response = $this->post("/repositories/{$this->path($repository)}/deploy-keys", [
            'key' => $publicKey,
            'label' => $title,
        ]);

        return (string) $response->json('id');
    }

    public function deleteDeployKey(string $repository, string $keyId): void
    {
        $this->delete("/repositories/{$this->path($repository)}/deploy-keys/".rawurlencode($keyId));
    }

    public function latestCommit(string $repository, string $branch): array
    {
        $body = $this->get("/repositories/{$this->path($repository)}/commits/".rawurlencode($branch), ['pagelen' => 1])->json();
        $commit = (array) (($body['values'] ?? [])[0] ?? []);

        return [
            'sha' => (string) ($commit['hash'] ?? ''),
            'message' => trim((string) ($commit['message'] ?? '')),
            'author' => (string) ($commit['author']['raw'] ?? ''),
        ];
    }

    public function updateCommitStatus(string $repository, string $sha, string $state, string $targetUrl, string $description): void
    {
        $this->post("/repositories/{$this->path($repository)}/commit/".rawurlencode($sha).'/statuses/build', [
            'key' => 'coresmith-deploy',
            'name' => 'Coresmith',
            'state' => match ($state) {
                'error', 'failure' => 'FAILED',
                'pending' => 'INPROGRESS',
                default => 'SUCCESSFUL',
            },
            'url' => $targetUrl,
            'description' => $description,
        ]);
    }

    public function revoke(): void
    {
        $this->provider->forceFill(['access_token' => null, 'refresh_token' => null, 'token_expires_at' => null])->save();
    }

    protected function apiRoot(): string
    {
        return self::API;
    }

    protected function tokenEndpoint(): string
    {
        return 'https://bitbucket.org/site/oauth2/access_token';
    }

    /**
     * @return array<string, string>
     */
    protected function credentials(): array
    {
        return [
            'client_id' => (string) config('services.bitbucket.client_id'),
            'client_secret' => (string) config('services.bitbucket.client_secret'),
        ];
    }

    protected function providerName(): string
    {
        return 'Bitbucket';
    }

    /**
     * @param  array<string, mixed>  $repository
     */
    private function sshUrl(array $repository): string
    {
        foreach ((array) ($repository['links']['clone'] ?? []) as $link) {
            if (($link['name'] ?? '') === 'ssh') {
                return (string) $link['href'];
            }
        }

        return 'git@bitbucket.org:'.$repository['full_name'].'.git';
    }

    private function path(string $repository): string
    {
        if (!preg_match('/^[A-Za-z0-9._-]+\/[A-Za-z0-9._-]+$/', $repository)) {
            throw new GitProviderException("\"{$repository}\" is not a valid workspace/repo name.");
        }

        return $repository;
    }
}
