<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\NoPreviousReleaseException;
use App\Models\Application;
use App\Services\Deployment\DeploymentCommands;
use App\Services\Deployment\ReleasePaths;
use App\Services\SSH\SSHConnectionManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use InvalidArgumentException;

final class RollbackApplication implements ShouldQueue
{
    use Queueable;

    public const RELEASE_PATTERN = '/^\d{8}-?\d{6}$/';

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(
        private readonly Application $application,
        private readonly ?string $release = null,
    ) {
        if ($release !== null && preg_match(self::RELEASE_PATTERN, $release) !== 1) {
            throw new InvalidArgumentException("[{$release}] is not a release name.");
        }

        $this->onQueue('deployments');
    }

    public function handle(SSHConnectionManager $connections, DeploymentCommands $commands): void
    {
        $connection = $connections->for($this->application->server);
        $paths = new ReleasePaths($this->application);

        try {
            $releases = $connection->execute('ls -1dt ' . $paths->releases() . '/*/ 2>/dev/null || true')->output;
            $ordered = array_values(array_filter(array_map(
                static fn(string $line): string => rtrim(trim($line), '/'),
                preg_split('/\R/', $releases) ?: [],
            )));

            $target = $this->target($ordered, $paths);
            $current = $paths->current();
            $previous = $this->application->current_release;

            $connection->execute("ln -sfn {$target} {$current}.tmp")->throw();
            $connection->execute("mv -Tf {$current}.tmp {$current}")->throw();

            $name = basename($target);

            $this->application->forceFill(['current_release' => $name])->save();

            foreach ($commands->restart($this->application) as $command) {
                $connection->execute($command, sudo: true)->throw();
            }

            activity()
                ->performedOn($this->application)
                ->withProperties(['from' => $previous, 'to' => $name])
                ->log('application_rolled_back');
        } finally {
            $connections->disconnect($this->application->server);
        }
    }

    /**
     * @param  list<string>  $ordered
     */
    private function target(array $ordered, ReleasePaths $paths): string
    {
        if ($this->release !== null) {
            $wanted = $paths->release($this->release);

            if (!in_array($wanted, $ordered, true)) {
                throw NoPreviousReleaseException::forApplication($this->application->slug);
            }

            return $wanted;
        }

        if (count($ordered) < 2) {
            throw NoPreviousReleaseException::forApplication($this->application->slug);
        }

        return $ordered[1];
    }
}
