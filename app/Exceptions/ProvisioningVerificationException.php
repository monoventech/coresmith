<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class ProvisioningVerificationException extends RuntimeException
{
    public static function missing(string $what): self
    {
        return new self("Provisioning finished but the {$what} is not present or not running.");
    }

    public static function adminPortOpen(int $port): self
    {
        return new self("Caddy still answers on 127.0.0.1:{$port}, where any program on the server can reach it. Provisioning has been halted.");
    }

    public static function adminApiExposed(string $ipAddress, int $port): self
    {
        return new self("The Caddy admin API is reachable from outside on {$ipAddress}:{$port}. Provisioning has been halted; the firewall is not restricting it as expected.");
    }
}
