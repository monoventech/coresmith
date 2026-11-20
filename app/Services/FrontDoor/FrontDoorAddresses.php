<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\FrontDoor;

use App\Models\Server;
use App\Models\ServerFrontDoor;
use Illuminate\Support\Str;

final class FrontDoorAddresses
{
    public const PREFIX = '10.205';

    /**
     * @return array{subnet: string, door: string, home: string}|null
     */
    public function allocate(Server $door): ?array
    {
        $served = ServerFrontDoor::query()->where('door_server_id', $door->getKey())->get(['subnet', 'home_address']);
        $prefix = $served->isEmpty() ? self::PREFIX . '.0' : self::prefix((string) $served->first()->subnet);
        $taken = $served->map(fn(ServerFrontDoor $row): int => (int) Str::afterLast((string) $row->home_address, '.'))->all();
        $host = 2;

        while (in_array($host, $taken, true)) {
            $host++;
        }

        if ($host > 254) {
            return null;
        }

        return self::addresses($prefix, $host);
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
