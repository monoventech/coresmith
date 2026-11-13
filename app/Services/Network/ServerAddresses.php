<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Network;

use App\Models\Server;
use App\Services\SSH\SSHConnection;

final class ServerAddresses
{
    public function read(SSHConnection $connection): ServerNetworkReading
    {
        return ServerNetworkReading::parse($connection->execute(RemoteReachProbe::script(), timeout: RemoteReachProbe::SECONDS + 10)->output);
    }

    public function keep(Server $server, ServerNetworkReading $reading): void
    {
        $before = (array) $server->public_addresses;

        $server->forceFill(['public_addresses' => [
            'ipv4' => $reading->direct?->address ?? $before['ipv4'] ?? null,
            'ipv4_open' => $reading->direct?->http ?? $before['ipv4_open'] ?? null,
            'ipv6' => $reading->stable,
            'checked_at' => now()->toIso8601String(),
        ]])->save();
    }

    /**
     * @param  array<string, mixed>|null  $addresses
     * @return list<array{type: string, value: string, closed: bool}>
     */
    public static function targets(?array $addresses): array
    {
        $ipv4 = is_string($addresses['ipv4'] ?? null) ? [['type' => 'A', 'value' => $addresses['ipv4'], 'closed' => ($addresses['ipv4_open'] ?? null) === false]] : [];
        $ipv6 = array_map(fn(string $address): array => ['type' => 'AAAA', 'value' => $address, 'closed' => false], array_values(array_filter((array) ($addresses['ipv6'] ?? []), is_string(...))));

        return ($ipv4[0]['closed'] ?? false) ? [...$ipv6, ...$ipv4] : [...$ipv4, ...$ipv6];
    }
}
