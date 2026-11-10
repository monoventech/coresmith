<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Server;

use App\Models\Server;
use App\Services\SSH\SSHConnection;
use Illuminate\Support\Arr;

final class FirewallConfigurator
{
    public const KEPT_SSH = 'kept';

    private const WEB_PORTS = [80, 443];

    public function configure(SSHConnection $connection): void
    {
        $server = $connection->server();
        $firewall = FirewallManagerFactory::for($server);
        $packages = PackageManagerFactory::for($server);

        $connection->execute($packages->install([FirewallManagerFactory::packageFor($server)]), sudo: true)->throw();

        $keepsSsh = $server->runs_coresmith && $connection->execute($firewall->isActive(), sudo: true)->successful();

        if (!$keepsSsh) {
            $connection->execute($firewall->denyIncomingByDefault(), sudo: true)->throw();
        }

        foreach ($keepsSsh ? self::WEB_PORTS : $this->requiredPorts($server->ssh_port) as $port) {
            $connection->execute($firewall->allow($port), sudo: true)->throw();
        }

        if (!$keepsSsh) {
            $connection->execute($firewall->enable(), sudo: true)->throw();
        }

        $server->forceFill(['meta' => [...Arr::except((array) $server->meta, 'firewall_ssh'), ...($keepsSsh ? ['firewall_ssh' => self::KEPT_SSH] : [])]])->save();
    }

    /**
     * @return list<int>
     */
    public function requiredPorts(int $sshPort): array
    {
        return array_values(array_unique([$sshPort, ...self::WEB_PORTS]));
    }

    /**
     * @return list<int>
     */
    public function openedPorts(Server $server): array
    {
        return self::keptSsh($server) ? self::WEB_PORTS : $this->requiredPorts($server->ssh_port);
    }

    public static function keptSsh(Server $server): bool
    {
        return (((array) $server->meta)['firewall_ssh'] ?? null) === self::KEPT_SSH;
    }
}
