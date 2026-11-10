<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ServerStatus;
use App\Exceptions\ServerConnectionFailed;
use App\Exceptions\ThisMachineRefused;
use App\Models\Server;
use App\Services\Server\ServerConnector;
use App\Services\Server\ThisMachine;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class ConnectThisMachine implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public bool $deleteWhenMissingModels = true;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public readonly Server $server) {}

    public function uniqueId(): string
    {
        return (string) $this->server->getKey();
    }

    public function handle(ThisMachine $machine, ServerConnector $connector): void
    {
        $server = $this->server->fresh();

        if ($server === null || !$server->runs_coresmith || $server->status !== ServerStatus::Pending) {
            return;
        }

        try {
            $access = $machine->add($server);
        } catch (ThisMachineRefused $refused) {
            $server->forceFill(['last_connection_error' => $refused->getMessage()])->save();

            return;
        }

        $server->forceFill(['ssh_port' => $access->port, 'host_key_fingerprint' => $access->fingerprint, 'last_connection_error' => null])->save();

        try {
            $connector->withKey($server);
        } catch (ServerConnectionFailed) {
            return;
        }
    }
}
