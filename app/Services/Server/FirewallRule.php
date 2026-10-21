<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Server;

use InvalidArgumentException;

final class FirewallRule
{
    public static function port(int $port): int
    {
        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException("[{$port}] is not a valid port number.");
        }

        return $port;
    }

    public static function protocol(string $protocol): string
    {
        $protocol = strtolower($protocol);

        if (!in_array($protocol, ['tcp', 'udp'], true)) {
            throw new InvalidArgumentException("[{$protocol}] is not a supported protocol.");
        }

        return $protocol;
    }

    public static function ip(string $ip): string
    {
        $address = explode('/', $ip, 2);

        if (filter_var($address[0], FILTER_VALIDATE_IP) === false) {
            throw new InvalidArgumentException("[{$ip}] is not a valid IP address.");
        }

        if (isset($address[1]) && preg_match('/^\d{1,3}$/D', $address[1]) !== 1) {
            throw new InvalidArgumentException("[{$ip}] is not a valid CIDR range.");
        }

        return $ip;
    }
}
