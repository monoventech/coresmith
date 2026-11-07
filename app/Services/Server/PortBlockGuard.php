<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Server;

use App\Models\Application;
use App\Services\SSH\SSHConnection;

final class PortBlockGuard
{
    public function __construct(
        private readonly PortScanner $scanner,
        private readonly PortAllocator $ports,
    ) {}

    /**
     * @return array{from: int, to: int, port: int, process: string}|array{unknown: true}|null
     */
    public function ensureFree(SSHConnection $connection, Application $application): ?array
    {
        $listeners = $this->scanner->listeners($connection);

        if ($listeners === []) {
            return ['unknown' => true];
        }

        $from = (int) $application->base_port;

        foreach ($listeners as $port => $process) {
            if (!PortAllocator::overlaps($from, [$port])) {
                continue;
            }

            $to = $this->ports->allocate($application->server, array_keys($listeners), $application);

            $application->forceFill(['base_port' => $to])->save();

            activity()
                ->performedOn($application)
                ->withProperties(['from' => $from, 'to' => $to, 'port' => $port, 'process' => $process])
                ->log('port_block_moved');

            return ['from' => $from, 'to' => $to, 'port' => $port, 'process' => $process];
        }

        return null;
    }
}
