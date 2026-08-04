<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ServerStatus;
use App\Models\Server;
use App\Services\Server\MetricsCollector;
use App\Services\SSH\SSHConnectionManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class CollectServerMetrics implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(private readonly Server $server)
    {
        $this->onQueue('default');
    }

    public function handle(SSHConnectionManager $connections, MetricsCollector $metrics): void
    {
        if ($this->server->status !== ServerStatus::Active) {
            return;
        }

        $connection = $connections->for($this->server);

        try {
            $metrics->collect($connection);
            $metrics->recordTraffic($connection);
        } finally {
            $connections->disconnect($this->server);
        }
    }
}
