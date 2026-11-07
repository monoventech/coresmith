<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Enums;

enum SourceProviderType: string
{
    case GitHubApp = 'github_app';
    case GitLab = 'gitlab';
    case Bitbucket = 'bitbucket';
    case Custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::GitHubApp => 'GitHub',
            self::GitLab => 'GitLab',
            self::Bitbucket => 'Bitbucket',
            self::Custom => 'Custom (SSH only)',
        };
    }

    public function authDescription(): string
    {
        return match ($this) {
            self::GitHubApp => 'GitHub App (installation)',
            self::GitLab => 'OAuth (token auto-refreshes every 2 hours)',
            self::Bitbucket => 'OAuth (token auto-refreshes every 2 hours)',
            self::Custom => 'SSH deploy key only',
        };
    }

    /**
     * @return list<string>
     */
    public function scopes(): array
    {
        return match ($this) {
            self::GitHubApp => ['contents:read', 'metadata:read', 'statuses:write'],
            self::GitLab => ['read_repository', 'read_api', 'api'],
            self::Bitbucket => ['repository', 'webhook', 'repository:admin'],
            self::Custom => [],
        };
    }

    public function usesTokens(): bool
    {
        return $this !== self::Custom;
    }

    public function managementUrl(): ?string
    {
        return match ($this) {
            self::GitHubApp => 'https://github.com/settings/installations',
            self::GitLab => 'https://gitlab.com/-/profile/applications',
            self::Bitbucket => 'https://bitbucket.org/account/settings/app-authorizations/',
            self::Custom => null,
        };
    }
}
