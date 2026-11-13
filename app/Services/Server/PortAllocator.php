<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Server;

use App\Exceptions\PortExhaustedException;
use App\Models\Application;
use App\Models\Server;
use App\Services\Deployment\RemovedApplicationFolders;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class PortAllocator
{
    public const RANGE_START = 8000;

    public const RANGE_END = 9999;

    public const BLOCK_SIZE = 10;

    public const SSR_RANGE_START = 13700;

    public const SSR_DEFAULT_PORT = 13714;

    public const SSR_RANGE_END = 13899;

    /**
     * @param  list<int>  $inUse
     */
    public function allocate(Server $server, array $inUse = [], ?Application $except = null): int
    {
        $used = $this->holders($server)
            ->when($except !== null, fn($query) => $query->whereKeyNot($except->getKey()))
            ->pluck('base_port')
            ->map(fn(mixed $port): int => (int) $port)
            ->all();

        for ($port = self::RANGE_START; $port <= self::RANGE_END; $port += self::BLOCK_SIZE) {
            if (!in_array($port, $used, true) && !self::overlaps($port, $inUse)) {
                return $port;
            }
        }

        throw PortExhaustedException::forServer($server->name);
    }

    /**
     * @param  list<int>  $inUse
     */
    public static function overlaps(int $base, array $inUse): bool
    {
        foreach ($inUse as $port) {
            if ($port >= $base && $port < $base + self::BLOCK_SIZE) {
                return true;
            }
        }

        return false;
    }

    public function allocateSsrPort(Server $server): int
    {
        $used = $this->holders($server)->pluck('ssr_port')->filter()->all();

        foreach ([self::SSR_DEFAULT_PORT, ...range(self::SSR_RANGE_START, self::SSR_RANGE_END)] as $port) {
            if (!in_array($port, $used, true)) {
                return $port;
            }
        }

        throw PortExhaustedException::ssrForServer($server->name);
    }

    /**
     * @return HasMany<Application, Server>
     */
    private function holders(Server $server): HasMany
    {
        return $server->applications()
            ->withTrashed()
            ->where(fn(Builder $query) => $query->whereNull('deleted_at')->orWhere('deleted_at', '>=', now()->subDays(RemovedApplicationFolders::KEEP_DAYS)));
    }

    public static function isInAllocatableRange(int $port): bool
    {
        return $port >= self::RANGE_START && $port <= self::RANGE_END;
    }

    public static function maximumApplications(): int
    {
        return intdiv(self::RANGE_END - self::RANGE_START + 1, self::BLOCK_SIZE);
    }
}
