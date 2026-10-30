<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Server;
use App\Services\Cloud\Tasks\CloudTask;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class ResumeCloudTasks implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct()
    {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        Server::query()
            ->where('provider_task->state', CloudTask::RUNNING)
            ->each(function (Server $server): void {
                if (CloudTask::of($server)?->isStale() === true) {
                    RunCloudTask::dispatch($server);
                }
            });
    }
}
