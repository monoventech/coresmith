<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\PowerState;
use App\Models\Application;
use App\Services\Deployment\CrontabBuilder;
use App\Services\Process\ProcessManager;
use App\Services\Process\SystemdUnitBuilder;
use App\Services\SSH\SSHConnectionManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

final class SyncApplicationProcesses implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    /**
     * @param  list<string>  $removed
     */
    public function __construct(
        private readonly Application $application,
        private readonly array $removed = [],
    ) {
        $this->onQueue('server-tasks');
    }

    public function handle(SSHConnectionManager $connections, ProcessManager $processes, SystemdUnitBuilder $units, CrontabBuilder $crontab): void
    {
        $application = $this->application->fresh() ?? $this->application;
        $connection = $connections->for($application->server);

        try {
            foreach ($this->removed as $process) {
                $unit = $units->unitName($application, $process);

                $connection->execute('systemctl disable --now ' . escapeshellarg($unit) . ' || true', sudo: true);
                $connection->execute('rm -f ' . escapeshellarg($units->unitPath($application, $process)), sudo: true)->throw();
            }

            $processes->install($connection, $application);

            if ($application->power_state === PowerState::Active) {
                $processes->start($connection, $application);
            }

            $slug = $application->slug;
            $connection->uploadContent($crontab->build($application), '/tmp/coresmith-cron-' . $slug);
            $connection->execute('cat /tmp/coresmith-cron-' . $slug . ' | ' . $crontab->install($application) . ' && rm -f /tmp/coresmith-cron-' . $slug, sudo: true)->throw();
        } catch (Throwable $e) {
            activity()
                ->performedOn($application)
                ->withProperties(['error' => mb_substr($e->getMessage(), 0, 300)])
                ->log('processes_sync_failed');

            throw $e;
        } finally {
            $connections->disconnect($application->server);
        }
    }
}
