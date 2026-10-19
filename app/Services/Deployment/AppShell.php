<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Deployment;

use App\Models\Application;
use App\Services\Runtime\AsdfManager;
use App\Services\SafeName;
use App\Services\SSH\Contracts\SSHTransport;

final class AppShell
{
    public const SYSTEM_PATH = '/usr/local/bin:/usr/bin:/bin';

    public function __construct(private readonly AsdfManager $asdf) {}

    /**
     * @param  array<string, string>  $versions
     */
    public function run(Application $application, string $command, string $directory, array $versions = [], int $seconds = SSHTransport::COMMAND_SECONDS): string
    {
        return $this->unit($application, $directory, $versions, $this->build($seconds)) . ' /bin/bash -c ' . escapeshellarg($command);
    }

    /**
     * @param  array<string, string>  $versions
     */
    public function runScript(Application $application, string $script, string $directory, array $versions = [], int $seconds = SSHTransport::COMMAND_SECONDS): string
    {
        return $this->unit($application, $directory, $versions, $this->build($seconds)) . ' /bin/bash -s < ' . escapeshellarg($script);
    }

    /**
     * @param  array<string, string>  $versions
     * @param  array<string, string>  $environment
     */
    public function scheduled(Application $application, string $script, string $directory, array $versions = [], array $environment = []): string
    {
        return $this->unit($application, $directory, $versions, ['KillMode=process'], $environment) . ' /bin/bash -s < ' . escapeshellarg($script);
    }

    /**
     * @param  array<string, string>  $versions
     */
    public function path(array $versions): string
    {
        $directories = [];

        foreach ($versions as $runtime => $version) {
            AsdfManager::assertValid($runtime, $version);
            $directories[] = $this->asdf->versionPath($runtime, $version) . '/bin';
        }

        return implode(':', [...$directories, self::SYSTEM_PATH]);
    }

    /**
     * @param  array<string, string>  $versions
     * @param  list<string>  $properties
     * @param  array<string, string>  $environment
     */
    private function unit(Application $application, string $directory, array $versions, array $properties, array $environment = []): string
    {
        $user = SafeName::assertSystemUser($application->system_user);

        return implode(' ', [
            'systemd-run --quiet --wait --pipe --collect --service-type=exec',
            '--uid=' . $user,
            '--gid=' . $user,
            '-p ' . escapeshellarg('WorkingDirectory=' . $directory),
            ...array_map(fn(string $name, string $value): string => '-E ' . escapeshellarg($name . '=' . $value), array_keys($environment), $environment),
            '-p ' . escapeshellarg('EnvironmentFile=' . (new ReleasePaths($application))->envFile()),
            ...array_map(fn(string $property): string => '-p ' . $property, $properties),
            '-p NoNewPrivileges=yes',
            '-E ' . escapeshellarg('PATH=' . $this->path($versions)),
            '--',
        ]);
    }

    /**
     * @return list<string>
     */
    private function build(int $seconds): array
    {
        return ['RuntimeMaxSec=' . max(1, $seconds), 'PrivateTmp=yes'];
    }
}
