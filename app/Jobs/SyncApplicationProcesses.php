<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\PowerState;
use App\Exceptions\ProcessDidNotStart;
use App\Models\Application;
use App\Services\Deployment\CrontabBuilder;
use App\Services\Deployment\LogSanitizer;
use App\Services\Panel\SmartToggles;
use App\Services\Process\ProcessManager;
use App\Services\Process\SystemdUnitBuilder;
use App\Services\SSH\SSHConnection;
use App\Services\SSH\SSHConnectionManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

final class SyncApplicationProcesses implements ShouldQueue
{
    use Queueable;

    public bool $deleteWhenMissingModels = true;

    public int $tries = 1;

    public int $timeout = 240;

    /**
     * @param  list<string>  $removed
     */
    public function __construct(
        private readonly Application $application,
        private readonly array $removed = [],
        private readonly ?string $toggle = null,
    ) {
        $this->onQueue('server-tasks');
    }

    public function handle(SSHConnectionManager $connections, ProcessManager $processes, SystemdUnitBuilder $units, CrontabBuilder $crontab, SmartToggles $toggles): void
    {
        $application = $this->application->fresh() ?? $this->application;
        $connection = $connections->for($application->server);

        try {
            foreach ($this->removed as $process) {
                $this->remove($connection, $units, $application, $process);
            }

            $processes->install($connection, $application);

            if ($application->power_state === PowerState::Active) {
                $processes->start($connection, $application);
            }

            $crontab->write($connection, $application);

            if ($application->power_state === PowerState::Active) {
                $this->verify($connection, $processes, $units, $toggles, $application);
            }
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

    private function verify(SSHConnection $connection, ProcessManager $processes, SystemdUnitBuilder $units, SmartToggles $toggles, Application $application): void
    {
        $toggle = $this->toggle;
        $process = $toggle === null ? null : $toggles->process($toggle);

        if ($toggle === null || $process === null || !$toggles->isOn($application, $toggle)) {
            return;
        }

        try {
            $processes->verifyStarted($connection, $application, [$units->unitName($application, $process)]);
        } catch (ProcessDidNotStart $e) {
            $this->remove($connection, $units, $application, $process);
            $connection->execute('systemctl daemon-reload', sudo: true)->throw();
            $toggles->failedToStart($application, $toggle, $process, app(LogSanitizer::class)->sanitize($e->getMessage(), $application));

            return;
        }

        $toggles->started($application, $toggle);
    }

    private function remove(SSHConnection $connection, SystemdUnitBuilder $units, Application $application, string $process): void
    {
        $unit = $units->unitName($application, $process);

        $connection->execute('systemctl disable --now ' . escapeshellarg($unit) . ' || true', sudo: true);
        $connection->execute('rm -f ' . escapeshellarg($units->unitPath($application, $process)), sudo: true)->throw();
    }
}
