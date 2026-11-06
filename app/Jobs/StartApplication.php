<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Application;
use App\Services\Deployment\CronSyncFailure;
use App\Services\Deployment\CrontabBuilder;
use App\Services\Process\ProcessManager;
use App\Services\SSH\SSHConnection;
use App\Services\SSH\SSHConnectionManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

final class StartApplication implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 120;

    public function __construct(private readonly Application $application)
    {
        $this->onQueue('server-tasks');
    }

    public function handle(SSHConnectionManager $connections, ProcessManager $processes, CrontabBuilder $crontab): void
    {
        $connection = $connections->for($this->application->server);

        try {
            $failure = null;

            try {
                $processes->start($connection, $this->application);
            } catch (Throwable $e) {
                $failure = $e;
            }

            $this->writeCron($crontab, $connection, $failure === null);

            if ($failure !== null) {
                throw $failure;
            }
        } finally {
            $connections->disconnect($this->application->server);
        }
    }

    private function writeCron(CrontabBuilder $crontab, SSHConnection $connection, bool $rethrow): void
    {
        try {
            $crontab->write($connection, $this->application);
        } catch (Throwable $e) {
            Application::query()->whereKey($this->application->getKey())->update(['cron_error' => CronSyncFailure::reason($e, $this->application)]);

            if ($rethrow) {
                throw $e;
            }
        }
    }
}
