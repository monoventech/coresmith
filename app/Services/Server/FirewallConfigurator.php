<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Server;

use App\Services\SSH\SSHConnection;

final class FirewallConfigurator
{
    public function configure(SSHConnection $connection): void
    {
        $server = $connection->server();
        $firewall = FirewallManagerFactory::for($server);
        $packages = PackageManagerFactory::for($server);

        $connection->execute($packages->install([FirewallManagerFactory::packageFor($server)]), sudo: true)->throw();

        $connection->execute($firewall->denyIncomingByDefault(), sudo: true)->throw();

        foreach ($this->requiredPorts($server->ssh_port) as $port) {
            $connection->execute($firewall->allow($port), sudo: true)->throw();
        }

        $connection->execute($firewall->enable(), sudo: true)->throw();
    }

    /**
     * @return list<int>
     */
    public function requiredPorts(int $sshPort): array
    {
        return array_values(array_unique([$sshPort, 80, 443]));
    }
}
