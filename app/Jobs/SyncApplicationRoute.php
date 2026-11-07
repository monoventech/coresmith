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

    public bool $deleteWhenMissingModels = true;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(private readonly Application $application, public readonly bool $unroute = false)
    {
        $this->onQueue('server-tasks');
    }

    public function handle(SSHConnectionManager $connections, CaddyApiClient $caddy): void
    {
        $routed = $this->application->allDomains() !== [];

        if (!$routed && !$this->unroute) {
            return;
        }

        $connection = $connections->for($this->application->server);

        try {
            if ($routed) {
                $caddy->putSite($connection, $this->application);
            } else {
                $caddy->removeSite($connection, $this->application);
            }
        } finally {
            $connections->disconnect($this->application->server);
        }
    }
}
