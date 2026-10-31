<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Application;
use App\Models\Deployment;
use App\Services\Deployment\DeploymentLog;
use App\Services\Deployment\ReleasePaths;
use App\Services\SSH\SSHConnectionManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

final class DiscardRelease implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 120;

    public function __construct(private readonly Deployment $deployment)
    {
        $this->onQueue('server-tasks');
    }

    public function handle(SSHConnectionManager $connections): void
    {
        $release = $this->deployment->release;
        $application = Application::withTrashed()->find($this->deployment->application_id);
        $server = $application?->server;

        if ($release === null || $application === null || $server === null) {
            return;
        }

        if ($release === $application->current_release || preg_match(RollbackApplication::RELEASE_PATTERN, $release) !== 1) {
            return;
        }

        $paths = new ReleasePaths($application);
        $connection = $connections->for($server);

        try {
            $current = $connection->execute('readlink ' . escapeshellarg($paths->current()), sudo: true);

            if ($current->successful() && basename(rtrim(trim($current->output), '/')) === $release) {
                return;
            }

            $connection->execute('rm -rf ' . escapeshellarg($paths->release($release)), sudo: true)->throw();
        } finally {
            $connections->disconnect($server);
        }
    }

    public function failed(Throwable $e): void
    {
        $deployment = $this->deployment->fresh();
        $release = $deployment?->release;

        if ($deployment === null || $release === null || preg_match(RollbackApplication::RELEASE_PATTERN, $release) !== 1) {
            return;
        }

        rescue(fn() => app(DeploymentLog::class)->append($deployment, "Could not remove the half-built release. Remove releases/{$release} by hand if it is still there."), report: true);
    }
}
