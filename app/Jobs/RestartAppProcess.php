<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Application;
use App\Models\User;
use App\Services\Process\ProcessManager;
use App\Services\SSH\SSHConnectionManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class RestartAppProcess implements ShouldQueue
{
    use Queueable;

    public bool $deleteWhenMissingModels = true;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(
        public readonly Application $application,
        public readonly ?string $process,
        public readonly ?User $by = null,
    ) {
        $this->onQueue('server-tasks');
    }

    public static function describe(?string $process): string
    {
        return match ($process) {
            null => 'every process',
            'php', 'web' => 'the web process',
            'queue' => 'the queue worker',
            'ssr' => 'Inertia SSR',
            'reverb' => 'Reverb',
            'horizon' => 'Horizon',
            'sidekiq' => 'Sidekiq',
            'jobs' => 'Solid Queue',
            'celery' => 'the Celery worker',
            'beat' => 'Celery beat',
            default => "the {$process} process",
        };
    }

    public function handle(SSHConnectionManager $connections, ProcessManager $processes): void
    {
        $connection = $connections->for($this->application->server);

        try {
            if ($this->process === null) {
                $processes->refresh($connection, $this->application, restart: true);
            } else {
                $processes->restart($connection, $this->application, $this->process);
            }
        } finally {
            $connections->disconnect($this->application->server);
        }

        activity()->performedOn($this->application)->causedBy($this->by)
            ->withProperties(['process' => self::describe($this->process)])
            ->log('process_restarted');
    }
}
