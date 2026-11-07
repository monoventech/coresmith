<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Git\Providers;

use App\Services\Git\Repository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

final class GitLabClient extends OAuthGitClient
{
    public function listRepositories(): Collection
    {
        $repositories = collect();
        $page = 1;

        do {
            $batch = (array) $this->get('/projects', [
                'membership' => true,
                'per_page' => 100,
                'page' => $page,
                'order_by' => 'last_activity_at',
            ])->json();

            foreach ($batch as $project) {
                $repositories->push(new Repository(
                    fullName: (string) $project['path_with_namespace'],
                    sshUrl: (string) $project['ssh_url_to_repo'],
                    defaultBranch: (string) ($project['default_branch'] ?? 'main'),
                    private: ($project['visibility'] ?? 'private') !== 'public',
                ));
            }

            $page++;
        } while (count($batch) === 100);

        return $repositories;
    }

    public function createWebhook(string $repository, string $url, string $secret): string
    {
        $response = $this->post("/projects/{$this->id($repository)}/hooks", [
            'url' => $url,
            'token' => $secret,
            'push_events' => true,
            'enable_ssl_verification' => true,
        ]);

        return (string) $response->json('id');
    }

    public function deleteWebhook(string $repository, string $hookId): void
    {
        $this->delete("/projects/{$this->id($repository)}/hooks/" . rawurlencode($hookId));
    }

    public function createDeployKey(string $repository, string $publicKey, string $title): string
    {
        $response = $this->post("/projects/{$this->id($repository)}/deploy_keys", [
            'title' => $title,
            'key' => $publicKey,
            'can_push' => false,
        ]);

        return (string) $response->json('id');
    }

    public function deleteDeployKey(string $repository, string $keyId): void
    {
        $this->delete("/projects/{$this->id($repository)}/deploy_keys/" . rawurlencode($keyId));
    }

    public function latestCommit(string $repository, string $branch): array
    {
        $commit = $this->get("/projects/{$this->id($repository)}/repository/commits/" . rawurlencode($branch))->json();

        return [
            'sha' => (string) ($commit['id'] ?? ''),
            'message' => (string) ($commit['title'] ?? ''),
            'author' => (string) ($commit['author_name'] ?? ''),
        ];
    }

    public function updateCommitStatus(string $repository, string $sha, string $state, string $targetUrl, string $description): void
    {
        $this->post("/projects/{$this->id($repository)}/statuses/" . rawurlencode($sha), [
            'state' => match ($state) {
                'error', 'failure' => 'failed',
                'pending' => 'running',
                default => 'success',
            },
            'target_url' => $targetUrl,
            'description' => $description,
            'name' => 'coresmith/deploy',
        ]);
    }

    public function revoke(): void
    {
        Http::asForm()->withOptions(self::NO_REDIRECTS)->timeout(20)->post($this->host() . '/oauth/revoke', array_merge($this->credentials(), [
            'token' => $this->currentToken(),
        ]));
    }

    protected function apiRoot(): string
    {
        return $this->host() . '/api/v4';
    }

    protected function tokenEndpoint(): string
    {
        return $this->host() . '/oauth/token';
    }

    /**
     * @return array<string, string>
     */
    protected function credentials(): array
    {
        return [
            'client_id' => (string) config('services.gitlab.client_id'),
            'client_secret' => (string) config('services.gitlab.client_secret'),
        ];
    }

    protected function providerName(): string
    {
        return 'GitLab';
    }

    private function host(): string
    {
        $custom = (string) ($this->provider->custom_host ?? '');

        return $custom !== '' ? rtrim($custom, '/') : 'https://gitlab.com';
    }

    private function id(string $repository): string
    {
        return rawurlencode($repository);
    }
}
