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

final class RollbackApplication implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(private readonly Application $application)
    {
        $this->onQueue('deployments');
    }

    public function handle(SSHConnectionManager $connections, DeploymentCommands $commands): void
    {
        $connection = $connections->for($this->application->server);
        $paths = new ReleasePaths($this->application);

        try {
            $releases = $connection->execute('ls -1dt '.$paths->releases().'/*/ 2>/dev/null || true')->output;
            $ordered = array_values(array_filter(array_map(
                static fn (string $line): string => rtrim(trim($line), '/'),
                preg_split('/\R/', $releases) ?: [],
            )));

            if (count($ordered) < 2) {
                throw NoPreviousReleaseException::forApplication($this->application->slug);
            }

            $previous = $ordered[1];
            $current = $paths->current();

            $connection->execute("ln -sfn {$previous} {$current}.tmp")->throw();
            $connection->execute("mv -Tf {$current}.tmp {$current}")->throw();

            foreach ($commands->restart($this->application) as $command) {
                $connection->execute($command, sudo: true)->throw();
            }
        } finally {
            $connections->disconnect($this->application->server);
        }
    }
}
