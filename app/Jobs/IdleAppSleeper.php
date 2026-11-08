<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\PowerState;
use App\Enums\ServerStatus;
use App\Models\Application;
use App\Services\Cloud\Tasks\CloudTask;
use App\Services\Process\ProcessManager;
use App\Services\SSH\SSHConnectionManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable;

final class IdleAppSleeper implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 30;

    public function __construct()
    {
        $this->onQueue('default');
    }

    public function handle(SSHConnectionManager $connections, ProcessManager $processes): void
    {
        Application::query()
            ->where('power_state', PowerState::Active->value)
            ->where('idle_timeout_minutes', '>', 0)
            ->whereNotNull('last_request_at')
            ->whereDoesntHave('server', fn(Builder $server): Builder => $server->where('status', ServerStatus::Moving->value))
            ->get()
            ->filter(fn(Application $application): bool => $application->last_request_at
                ->addMinutes((int) $application->idle_timeout_minutes)
                ->isPast() && ($application->server === null || !CloudTask::running($application->server)))
            ->each(function (Application $application) use ($connections, $processes): void {
                $connection = $connections->for($application->server);

                try {
                    $processes->sleep($connection, $application);
                } finally {
                    $connections->disconnect($application->server);
                }
            });
    }
}
