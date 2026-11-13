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
        return new self("The web server's controls still answer on 127.0.0.1:{$port}, where any program on the server could change its routing, so provisioning stopped. Start provisioning again.");
    }

    public static function adminApiExposed(string $ipAddress, int $port): self
    {
        return new self("The web server's controls answer from outside on {$ipAddress}:{$port}, so provisioning stopped. Check that the server's firewall blocks port {$port}, then start provisioning again.");
    }
}
