<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ServerStatus;
use App\Models\Server;
use App\Services\Cloud\Tasks\CloudTask;
use App\Services\Server\MetricsCollector;
use App\Services\Server\ServerReachability;
use App\Services\SSH\SSHConnectionManager;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

final class CollectServerMetrics implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public bool $deleteWhenMissingModels = true;

    public int $tries = 1;

    public int $timeout = 120;

    public int $uniqueFor = 180;

    public function __construct(private readonly Server $server)
    {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return (string) $this->server->getKey();
    }

    public function handle(SSHConnectionManager $connections, MetricsCollector $metrics, ServerReachability $reachability): void
    {
        if ($this->server->status !== ServerStatus::Active || $this->server->pending_host_key_fingerprint !== null || CloudTask::running($this->server)) {
            return;
        }

        $connection = $connections->for($this->server);
        $answered = false;

        try {
            $metrics->collect($connection);
            $reachability->answered($this->server);
            $answered = true;
            $metrics->recordTraffic($connection);
            $metrics->recordOomKills($connection);
        } catch (Throwable $e) {
            if ($answered || !ServerReachability::isConnectionFailure($e)) {
                throw $e;
            }

            $reachability->failed($this->server, $e);
        } finally {
            $connections->disconnect($this->server);
        }
    }
}
