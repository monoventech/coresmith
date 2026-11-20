<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Jobs;

use App\Events\FleetChanged;
use App\Models\Server;
use App\Models\ServerFrontDoor;
use App\Services\FrontDoor\FrontDoorSync;
use App\Services\SSH\SSHConnectionManager;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

final class SyncServerFrontDoor implements ShouldQueue
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
        return [(new WithoutOverlapping('server-front-door:' . $this->server->getKey()))->releaseAfter(self::WAIT_SECONDS)->expireAfter($this->timeout)];
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(30);
    }

    public static function whenOpen(?Server $server): void
    {
        if ($server !== null && ServerFrontDoor::query()->where('server_id', $server->getKey())->where('state', ServerFrontDoor::OPEN)->exists()) {
            self::dispatch($server);
        }
    }

    public function handle(SSHConnectionManager $connections, FrontDoorSync $sync): void
    {
        $frontDoor = ServerFrontDoor::query()->where('server_id', $this->server->getKey())->with(['home', 'door'])->first();

        if ($frontDoor === null) {
            return;
        }

        $door = $frontDoor->door;

        try {
            $home = $connections->for($this->server);
            $doorConnection = $door === null ? null : $connections->for($door);

            if ($frontDoor->state !== ServerFrontDoor::CLOSING && $doorConnection !== null) {
                $sync->open($frontDoor, $home, $doorConnection);
                $frontDoor = $frontDoor->fresh(['home', 'door']);
            }

            if ($frontDoor?->state === ServerFrontDoor::CLOSING) {
                $sync->close($frontDoor, $home, $doorConnection);
            }
        } finally {
            $connections->disconnect($this->server);

            if ($door !== null) {
                $connections->disconnect($door);
            }
        }

        FleetChanged::forServer($this->server);
    }
}
