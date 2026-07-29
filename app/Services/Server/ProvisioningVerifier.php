<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Server;

use App\Exceptions\ProvisioningVerificationException;
use App\Services\Caddy\CaddyApiClient;
use App\Services\Runtime\AsdfManager;
use App\Services\SSH\SSHConnection;

final class ProvisioningVerifier
{
    public function verify(SSHConnection $connection): void
    {
        $this->assert($connection, 'test -d '.AsdfManager::DATA_DIR, 'shared runtime directory');
        $this->assert($connection, 'test -d /var/coresmith/apps', 'application directory');
        $this->assert($connection, 'systemctl is-active --quiet caddy', 'Caddy service');
        $this->assert(
            $connection,
            'curl -fsS --max-time 10 http://localhost:'.CaddyApiClient::ADMIN_PORT.'/config/ >/dev/null',
            'Caddy admin API',
        );

        $this->assertAdminApiIsNotPubliclyReachable($connection);
    }

    private function assertAdminApiIsNotPubliclyReachable(SSHConnection $connection): void
    {
        $port = CaddyApiClient::ADMIN_PORT;
        $address = $connection->server()->ip_address;

        $result = $connection->execute(
            "curl -fsS --max-time 5 http://{$address}:{$port}/config/ >/dev/null 2>&1 && echo reachable || echo blocked",
        );

        if (trim($result->output) !== 'blocked') {
            throw ProvisioningVerificationException::adminApiExposed($address, $port);
        }
    }

    private function assert(SSHConnection $connection, string $command, string $what): void
    {
        if ($connection->execute($command)->failed()) {
            throw ProvisioningVerificationException::missing($what);
        }
    }
}
