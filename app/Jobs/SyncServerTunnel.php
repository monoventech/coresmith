<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Jobs;

use App\Events\FleetChanged;
use App\Models\Server;
use App\Models\ServerTunnel;
use App\Services\SSH\SSHConnectionManager;
use App\Services\Tunnel\TunnelSync;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

final class SyncServerTunnel implements ShouldQueue
{
    use Queueable;

    public const WAIT_SECONDS = 15;

    public bool $deleteWhenMissingModels = true;

    public int $maxExceptions = 1;

    public int $timeout = 900;

    public function __construct(public readonly Server $server)
    {
        $this->onQueue('server-tasks');
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('server-tunnel:' . $this->server->getKey()))->releaseAfter(self::WAIT_SECONDS)->expireAfter($this->timeout)];
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(30);
    }

    public function handle(SSHConnectionManager $connections, TunnelSync $sync): void
    {
        $tunnel = ServerTunnel::query()->where('server_id', $this->server->getKey())->first();

        if ($tunnel === null) {
            return;
        }

        try {
            $connection = $connections->for($this->server);

            if ($tunnel->state !== ServerTunnel::CLOSING) {
                $sync->open($tunnel, $connection);
                $tunnel = $tunnel->fresh();
            }

            if ($tunnel?->state === ServerTunnel::CLOSING) {
                $sync->close($tunnel, $connection);
            }
        } finally {
            $connections->disconnect($this->server);
        }

        FleetChanged::forServer($this->server);
    }

    public static function whenCarrying(?Server $server): void
    {
        if ($server !== null && ServerTunnel::query()->where('server_id', $server->getKey())->where('state', ServerTunnel::OPEN)->exists()) {
            self::dispatch($server);
        }
    }
}
