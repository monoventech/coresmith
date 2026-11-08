<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\SSH;

use App\Models\Server;
use App\Services\SSH\Contracts\SSHTransport;
use Closure;

final class SSHConnectionManager
{
    /** @var array<string, SSHConnection> */
    private array $connections = [];

    private static ?Closure $transportFactory = null;

    public static function createTransportUsing(?callable $factory): void
    {
        self::$transportFactory = $factory === null ? null : Closure::fromCallable($factory);
    }

    public function for(Server $server): SSHConnection
    {
        return $this->connections[$server->getKey()] ??= new SSHConnection($server, $this->makeTransport());
    }

    public function fresh(Server $server): SSHConnection
    {
        return new SSHConnection($server, $this->makeTransport());
    }

    public function probe(Server $server, #[\SensitiveParameter] string $privateKey): SSHConnection
    {
        $copy = clone $server;
        $copy->ssh_private_key = $privateKey;

        return new SSHConnection($copy, $this->makeTransport());
    }

    public function disconnect(Server $server): void
    {
        $key = $server->getKey();

        if (isset($this->connections[$key])) {
            $this->connections[$key]->disconnect();
            unset($this->connections[$key]);
        }
    }

    public function disconnectAll(): void
    {
        foreach ($this->connections as $connection) {
            $connection->disconnect();
        }

        $this->connections = [];
    }

    private function makeTransport(): SSHTransport
    {
        return self::$transportFactory !== null
            ? (self::$transportFactory)()
            : new Phpseclib3Transport;
    }
}
