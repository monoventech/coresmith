<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\FrontDoor;

use App\Models\Server;
use App\Models\ServerFrontDoor;
use Illuminate\Support\Str;
use RuntimeException;

final class FrontDoorAddresses
{
    public const PREFIX = '10.205';

    /**
     * @return array{subnet: string, door: string, home: string}
     */
    public function allocate(Server $door): array
    {
        $served = ServerFrontDoor::query()->where('door_server_id', $door->getKey())->get(['subnet', 'home_address']);

        if ($served->isNotEmpty()) {
            $prefix = self::prefix((string) $served->first()->subnet);
            $taken = $served->map(fn(ServerFrontDoor $row): int => (int) Str::afterLast((string) $row->home_address, '.'))->all();
            $host = 2;

            while (in_array($host, $taken, true)) {
                $host++;
            }

            if ($host > 254) {
                throw new RuntimeException("{$door->name} can't take any more servers through its front door.");
            }

            return self::addresses($prefix, $host);
        }

        $used = ServerFrontDoor::query()->pluck('subnet')->map(fn(mixed $subnet): string => self::prefix((string) $subnet))->all();

        for ($range = 0; $range <= 255; $range++) {
            $prefix = self::PREFIX . ".{$range}";

            if (!in_array($prefix, $used, true)) {
                return self::addresses($prefix, 2);
            }
        }

        throw new RuntimeException('Every front door address range is in use.');
    }

    /**
     * @return array{subnet: string, door: string, home: string}
     */
    private static function addresses(string $prefix, int $host): array
    {
        return ['subnet' => "{$prefix}.0/24", 'door' => "{$prefix}.1", 'home' => "{$prefix}.{$host}"];
    }

    private static function prefix(string $subnet): string
    {
        return Str::beforeLast(Str::before($subnet, '/'), '.');
    }
}
