<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Deployment;

use App\Models\Application;
use App\Services\Runtime\AsdfManager;
use App\Services\Runtime\Stack;

final class DeploymentCommands
{
    public function __construct(private readonly AsdfManager $asdf) {}

    /**
     * @param  array<string, string>  $versions
     */
    public function pathPrefix(array $versions): string
    {
        $directories = [];

        foreach ($versions as $runtime => $version) {
            AsdfManager::assertValid($runtime, $version);
            $directories[] = $this->asdf->versionPath($runtime, $version) . '/bin';
        }

        return $directories === [] ? '' : 'PATH=' . implode(':', $directories) . ':$PATH ';
    }

    /**
     * @return list<string>
     */
    public function dependencies(Application $application, Stack $stack): array
    {
        return $application->type->language()->install($application, $stack);
    }

    /**
     * @return list<string>
     */
    public function build(Application $application, Stack $stack): array
    {
        return $application->type->language()->build($application, $stack);
    }

    /**
     * @return list<string>
     */
    public function migrations(Application $application, Stack $stack): array
    {
        return $application->run_migrations ? $application->type->language()->migrations($application, $stack) : [];
    }

    /**
     * @return array<string, string>
     */
    public function healthChecks(Application $application, ReleasePaths $paths, string $release): array
    {
        return $application->type->language()->releaseChecks($application, $paths, $release);
    }
}
