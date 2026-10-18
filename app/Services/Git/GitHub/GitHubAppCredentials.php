<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Git\GitHub;

final readonly class GitHubAppCredentials
{
    public function __construct(
        public string $key,
        public string $appId,
        public string $slug,
        public string $name,
        public string $clientId,
        public string $clientSecret,
        public string $privateKey,
        public ?string $webhookSecret,
        public ?string $ownerLogin,
        public ?string $ownerType,
        public ?string $htmlUrl,
        public ?string $hookUrl,
    ) {}

    public function isEnv(): bool
    {
        return $this->key === GitHubApps::ENV_KEY;
    }

    public function installUrl(string $state): string
    {
        return 'https://github.com/apps/' . rawurlencode($this->slug) . '/installations/new?' . http_build_query(['state' => $state]);
    }

    public function settingsUrl(): ?string
    {
        return match ($this->ownerType) {
            'Organization' => $this->ownerLogin === null ? null : 'https://github.com/organizations/' . rawurlencode($this->ownerLogin) . '/settings/apps/' . rawurlencode($this->slug),
            'User' => 'https://github.com/settings/apps/' . rawurlencode($this->slug),
            default => null,
        };
    }
}
