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
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

final class RedisPorts
{
    public const LOCK_SECONDS = 30;

    /**
     * @param  list<int>  $busy
     */
    public function next(Server $server, array $busy = []): int
    {
        $used = ManagedDatabase::query()
            ->where('server_id', $server->getKey())
            ->where('type', DatabaseType::Redis->value)
            ->pluck('port')
            ->map(fn(mixed $port): int => (int) $port)
            ->all();

        for ($port = RedisInstance::FIRST_PORT; $port <= RedisInstance::LAST_PORT; $port++) {
            if (!in_array($port, $used, true) && !in_array($port, $busy, true)) {
                return $port;
            }
        }

        throw new RuntimeException("{$server->name} has no Redis port left between " . RedisInstance::FIRST_PORT . ' and ' . RedisInstance::LAST_PORT . '. Delete a Redis database you no longer need, then try again.');
    }

    public function lock(Server $server): Lock
    {
        return Cache::lock('redis-ports:' . $server->getKey(), self::LOCK_SECONDS);
    }

    public function confirm(SSHConnection $connection, ManagedDatabase $database): void
    {
        $port = (int) $database->port;
        $listening = $this->listening($connection);

        if (!in_array($port, $listening, true) || $connection->execute('systemctl is-active --quiet ' . RedisInstance::unit($port))->successful()) {
            return;
        }

        $server = $connection->server();

        $this->lock($server)->block(self::LOCK_SECONDS, fn() => $database->forceFill(['port' => $this->next($server, $listening)])->save());
    }

    /**
     * @return list<int>
     */
    public function listening(SSHConnection $connection): array
    {
        preg_match_all('/^\S+\s+\d+\s+\d+\s+\S*:(\d+)\s/m', $connection->execute('ss -Hltn')->output, $matches);

        return array_values(array_unique(array_map('intval', $matches[1])));
    }
}
