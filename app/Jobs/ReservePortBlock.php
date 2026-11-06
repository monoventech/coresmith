<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ServerStatus;
use App\Models\Application;
use App\Services\Server\PortBlockGuard;
use App\Services\SSH\SSHConnectionManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class ReservePortBlock implements ShouldQueue
{
    use Queueable;

    public bool $deleteWhenMissingModels = true;

    public int $tries = 2;

    public int $timeout = 60;

    public function __construct(private readonly Application $application)
    {
        $this->onQueue('server-tasks');
    }

    public function handle(SSHConnectionManager $connections, PortBlockGuard $guard): void
    {
        $server = $this->application->server;

        if ($server === null || $server->status !== ServerStatus::Active || $this->application->current_release !== null) {
            return;
        }

        try {
            $guard->ensureFree($connections->for($server), $this->application);
        } finally {
            $connections->disconnect($server);
        }
    }
}
