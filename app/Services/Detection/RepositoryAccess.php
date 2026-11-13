<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Detection;

use App\Models\Application;
use App\Models\SourceProvider;
use App\Services\Git\DeployKeyManager;

final readonly class RepositoryAccess
{
    private function __construct(
        public string $url,
        public ?SourceProvider $github = null,
        public ?string $repositoryId = null,
        public ?string $fullName = null,
        public ?Application $application = null,
    ) {}

    public static function public(string $url): self
    {
        return new self($url);
    }

    public static function github(SourceProvider $connection, string $repositoryId, string $fullName): self
    {
        return new self('https://github.com/' . $fullName . '.git', $connection, $repositoryId, $fullName);
    }

    public static function deployKey(Application $application): self
    {
        return new self((string) $application->repository_url, application: $application);
    }

    public static function forApplication(Application $application): self
    {
        $connection = $application->githubConnection();

        if ($connection !== null && $application->usesGitHubToken()) {
            return self::github($connection, (string) $application->repository_id, (string) $application->repositoryFullName());
        }

        return app(DeployKeyManager::class)->usesDeployKey($application) ? self::deployKey($application) : self::public((string) $application->repository_url);
    }

    public function key(): string
    {
        return $this->github === null ? $this->url : 'github:' . $this->github->getKey() . ':' . $this->repositoryId;
    }
}
