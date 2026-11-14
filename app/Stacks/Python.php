<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Stacks;

use App\Models\Application;
use App\Services\Deployment\ReleasePaths;
use App\Services\Runtime\RepositoryFacts;
use App\Services\Runtime\ResolvedRuntime;
use App\Services\Runtime\Stack;

final readonly class Python implements Language
{
    public function install(Application $application, Stack $stack): array
    {
        return [];
    }

    public function build(Application $application, Stack $stack): array
    {
        return [];
    }

    public function migrations(Application $application, Stack $stack): array
    {
        return [];
    }

    public function releaseChecks(Application $application, ReleasePaths $paths, string $release): array
    {
        return [];
    }

    public function webCommand(Application $application): ?string
    {
        return null;
    }

    public function usesFpm(): bool
    {
        return false;
    }

    public function route(Application $application): Route
    {
        return Route::ReverseProxy;
    }

    public function storageSeed(): StorageSeed
    {
        return StorageSeed::None;
    }

    public function initialEnvironment(?string $framework): array
    {
        return [];
    }

    public function processEnvironment(Application $application): array
    {
        return ['PYTHONUNBUFFERED' => '1'];
    }

    public function listenEnvironment(Application $application): array
    {
        return ['PORT' => (string) $application->base_port, 'HOST' => '127.0.0.1'];
    }

    /**
     * @return array{type: ?string, framework: ?string, js_runtime: ?string, web: ?string, sources: array<string, string>, unsettled: array<string, string>}|null
     */
    public function detect(RepositoryFacts $facts): ?array
    {
        return null;
    }

    public function checks(Application $choice, string $framework, RepositoryFacts $facts, ?string $arch): array
    {
        return [];
    }

    public function versionDoubt(RepositoryFacts $facts, ?ResolvedRuntime $runtime): ?string
    {
        return null;
    }

    public function cronExample(Application $application): string
    {
        return strtolower((string) $application->framework) === 'django' ? 'python manage.py clearsessions' : 'python cron/cleanup.py';
    }
}
