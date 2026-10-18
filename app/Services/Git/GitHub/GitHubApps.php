<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Git\GitHub;

use App\Models\GitHubApp;
use App\Models\SourceProvider;
use App\Models\Team;

final class GitHubApps
{
    public const ENV_KEY = 'env';

    public function env(): ?GitHubAppCredentials
    {
        $appId = $this->setting('app_id');
        $clientId = $this->setting('client_id');
        $clientSecret = $this->setting('client_secret');
        $privateKey = $this->pem($this->setting('private_key'));

        if ($appId === '' || $clientId === '' || $clientSecret === '' || $privateKey === '') {
            return null;
        }

        $slug = $this->setting('app_slug');
        $slug = $slug === '' ? 'coresmith' : $slug;
        $webhookSecret = $this->setting('webhook_secret');

        return new GitHubAppCredentials(
            key: self::ENV_KEY,
            appId: $appId,
            slug: $slug,
            name: 'Coresmith',
            clientId: $clientId,
            clientSecret: $clientSecret,
            privateKey: $privateKey,
            webhookSecret: $webhookSecret === '' ? null : $webhookSecret,
            ownerLogin: null,
            ownerType: null,
            htmlUrl: "https://github.com/apps/{$slug}",
            hookUrl: null,
        );
    }

    public function fromModel(GitHubApp $app): GitHubAppCredentials
    {
        return new GitHubAppCredentials(
            key: (string) $app->getKey(),
            appId: (string) $app->app_id,
            slug: (string) $app->slug,
            name: (string) $app->name,
            clientId: (string) $app->client_id,
            clientSecret: (string) $app->client_secret,
            privateKey: (string) $app->private_key,
            webhookSecret: $app->webhook_secret,
            ownerLogin: $app->owner_login,
            ownerType: $app->owner_type,
            htmlUrl: $app->html_url,
            hookUrl: $app->hook_url,
        );
    }

    public function forKey(string $key): ?GitHubAppCredentials
    {
        if ($key === self::ENV_KEY) {
            return $this->env();
        }

        $app = GitHubApp::query()->find($key);

        return $app === null ? null : $this->fromModel($app);
    }

    public function for(SourceProvider $provider): ?GitHubAppCredentials
    {
        return $provider->github_app_id === null ? $this->env() : $this->forKey((string) $provider->github_app_id);
    }

    /**
     * @return list<GitHubAppCredentials>
     */
    public function available(Team $team): array
    {
        $env = $this->env();

        if ($env !== null) {
            return [$env];
        }

        return $team->githubApps()->latest()->latest('id')->get()
            ->map(fn(GitHubApp $app): GitHubAppCredentials => $this->fromModel($app))
            ->all();
    }

    public function canRegister(): bool
    {
        return $this->env() === null;
    }

    private function setting(string $key): string
    {
        return trim((string) config("services.github.{$key}"));
    }

    private function pem(string $value): string
    {
        if (!str_contains($value, 'BEGIN')) {
            $decoded = base64_decode($value, true);

            $value = $decoded === false ? '' : $decoded;
        }

        return str_replace('\n', "\n", $value);
    }
}
