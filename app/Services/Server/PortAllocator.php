<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Server;

use App\Exceptions\PortExhaustedException;
use App\Models\Server;

final class PortAllocator
{
    public const RANGE_START = 8000;

    public const RANGE_END = 9999;

    public const BLOCK_SIZE = 10;

    public const SSR_RANGE_START = 13700;

    public const SSR_RANGE_END = 13899;

    public function allocate(Server $server): int
    {
        $used = $server->applications()->withTrashed()->pluck('base_port')->all();

        for ($port = self::RANGE_START; $port <= self::RANGE_END; $port += self::BLOCK_SIZE) {
            if (!in_array($port, $used, true)) {
                return $port;
            }
        }

        throw PortExhaustedException::forServer($server->name);
    }

    public function allocateSsrPort(Server $server): int
    {
        $used = $server->applications()->withTrashed()->pluck('ssr_port')->filter()->all();

        for ($port = self::SSR_RANGE_START; $port <= self::SSR_RANGE_END; $port++) {
            if (!in_array($port, $used, true)) {
                return $port;
            }
        }

        throw PortExhaustedException::ssrForServer($server->name);
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
