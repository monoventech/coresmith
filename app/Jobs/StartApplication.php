<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Application;
use App\Services\Process\ProcessManager;
use App\Services\SSH\SSHConnectionManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class StartApplication implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 60;

    public function __construct(private readonly Application $application)
    {
        $this->onQueue('server-tasks');
    }

    public function handle(SSHConnectionManager $connections, ProcessManager $processes): void
    {
        $connection = $connections->for($this->application->server);

        try {
            $processes->start($connection, $this->application);
        } finally {
            $connections->disconnect($this->application->server);
        }
    }
}
