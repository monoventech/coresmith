<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Application;
use App\Services\Caddy\CaddyApiClient;
use App\Services\SSH\SSHConnectionManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class SyncApplicationRoute implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(private readonly Application $application)
    {
        $this->onQueue('server-tasks');
    }

    public function handle(SSHConnectionManager $connections, CaddyApiClient $caddy): void
    {
        if ($this->application->allDomains() === []) {
            return;
        }

        $connection = $connections->for($this->application->server);

        try {
            $caddy->updateSite($connection, $this->application);
            $caddy->persistConfig($connection);
        } finally {
            $connections->disconnect($this->application->server);
        }
    }
}
