<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Database;

use App\Enums\DatabaseType;
use App\Models\ManagedDatabase;
use App\Models\Server;
use App\Services\SSH\SSHConnection;

final class SharedRedis
{
    /**
     * @return array{databases: int, can_turn_off: bool}|null
     */
    public function state(Server $server): ?array
    {
        $databases = ManagedDatabase::query()
            ->where('server_id', $server->getKey())
            ->where('type', DatabaseType::Redis->value)
            ->where('port', ManagedDatabase::SHARED_REDIS_PORT)
            ->count();

        if ($databases === 0 && !$this->ours($server)) {
            return null;
        }

        return ['databases' => $databases, 'can_turn_off' => $databases === 0 && $this->ours($server)];
    }

    public function connections(SSHConnection $connection): int
    {
        $clients = $connection->execute('redis-cli -p ' . ManagedDatabase::SHARED_REDIS_PORT . ' CLIENT LIST', sudo: true)->output;

        return count(array_filter(explode("\n", $clients), fn(string $line): bool => str_starts_with($line, 'id=') && !str_contains($line, 'cmd=client')));
    }

    public function turnOff(SSHConnection $connection): void
    {
        $server = $connection->server();

        $connection->execute('systemctl disable --now ' . (in_array($server->os_name, ['ubuntu', 'debian'], true) ? 'redis-server' : 'redis'), sudo: true)->throw();

        $server->forceFill(['meta' => [...(array) $server->meta, 'shared_redis' => 'off']])->save();
    }

    private function ours(Server $server): bool
    {
        $meta = (array) $server->meta;

        return in_array(DatabaseType::Redis->value, (array) ($meta['installed_databases'] ?? []), true) && ($meta['shared_redis'] ?? null) !== 'off';
    }
}
