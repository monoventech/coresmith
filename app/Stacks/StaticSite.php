<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Stacks;

use App\Models\Application;
use App\Services\Deployment\ReleasePaths;
use App\Services\Runtime\Stack;

final readonly class StaticSite implements Language
{
    public function __construct(private JsCommands $js) {}

    public function install(Application $application, Stack $stack): array
    {
        return $this->js->install($stack);
    }

    public function build(Application $application, Stack $stack): array
    {
        return $this->js->build($application, $stack);
    }

    public function migrations(Application $application, Stack $stack): array
    {
        return [];
    }

    public function releaseChecks(Application $application, ReleasePaths $paths, string $release): array
    {
        $web = trim((string) $application->web_directory, '/');

        return [($web === '' ? 'the release root' : $web . '/') => 'test -d ' . escapeshellarg($paths->webRoot($release))];
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
        return Route::FileServer;
    }
}
