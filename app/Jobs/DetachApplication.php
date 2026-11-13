<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Application;
use App\Services\Caddy\CaddyApiClient;
use App\Services\Deployment\CrontabBuilder;
use App\Services\Process\ProcessManager;
use App\Services\Server\FirewallManagerFactory;
use App\Services\SSH\SSHConnectionManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class DetachApplication implements ShouldQueue
{
    use Queueable;

    public bool $deleteWhenMissingModels = true;

    public int $tries = 2;

    public int $timeout = 120;

    public function __construct(private readonly Application $application)
    {
        $this->onQueue('server-tasks');
    }

    public function handle(SSHConnectionManager $connections, ProcessManager $processes, CaddyApiClient $caddy, CrontabBuilder $crontab): void
    {
        $connection = $connections->for($this->application->server);

        try {
            $processes->uninstall($connection, $this->application);
            $crontab->remove($connection, $this->application);

            if ($this->application->allDomains() !== []) {
                $caddy->removeSite($connection, $this->application);
                $caddy->persistConfig($connection);
            }

            $caddy->removeAddress($connection, $this->application, 'tailnet');

            if ($caddy->removeAddress($connection, $this->application, 'preview') && !$this->application->server->runs_coresmith) {
                $connection->execute(FirewallManagerFactory::for($this->application->server)->remove($this->application->addressPort()), sudo: true);
            }
        } finally {
            $connections->disconnect($this->application->server);
        }
    }
}
