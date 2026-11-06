<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Application;
use App\Services\Deployment\CronSyncFailure;
use App\Services\Deployment\CrontabBuilder;
use App\Services\Panel\CronJobs;
use App\Services\SSH\SSHConnectionManager;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\MaxAttemptsExceededException;
use Throwable;

final class SyncApplicationCron implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public bool $deleteWhenMissingModels = true;

    public const FAILED = 'cron_sync_failed';

    public int $tries = 1;

    public int $timeout = 120;

    public int $uniqueFor = 120;

    public function __construct(private readonly Application $application)
    {
        $this->onQueue('server-tasks');
    }

    public function uniqueId(): string
    {
        return (string) $this->application->getKey();
    }

    public function handle(SSHConnectionManager $connections, CrontabBuilder $crontab, CronJobs $cron): void
    {
        $application = Application::query()->find($this->application->getKey());

        if ($application === null || $application->server === null) {
            return;
        }

        if ($application->current_release === null) {
            $crontab->settle($application);

            return;
        }

        try {
            $crontab->write($connections->for($application->server), $application);
        } catch (Throwable $e) {
            $this->record($cron, $application, CronSyncFailure::reason($e, $application));

            throw $e;
        } finally {
            $connections->disconnect($application->server);
        }
    }

    public function failed(Throwable $e): void
    {
        if (!$e instanceof MaxAttemptsExceededException) {
            return;
        }

        $application = Application::query()->find($this->application->getKey());

        if ($application === null) {
            return;
        }

        $this->record(app(CronJobs::class), $this->application, CronSyncFailure::reason($e, $application));
    }

    private function record(CronJobs $cron, Application $read, string $reason): void
    {
        $cron->ifUnchanged($read, ['cron_error' => $reason, 'cron_pending_since' => null]);

        activity()->performedOn($read)->withProperties(['error' => $reason])->log(self::FAILED);
    }
}
