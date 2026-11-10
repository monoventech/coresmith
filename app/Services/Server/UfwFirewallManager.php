<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Server;

use App\Services\Server\Contracts\FirewallManager;

final class UfwFirewallManager implements FirewallManager
{
    public function allow(int $port, string $protocol = 'tcp'): string
    {
        return 'ufw allow ' . FirewallRule::port($port) . '/' . FirewallRule::protocol($protocol);
    }

    public function deny(int $port, string $protocol = 'tcp'): string
    {
        return 'ufw deny ' . FirewallRule::port($port) . '/' . FirewallRule::protocol($protocol);
    }

    public function allowFrom(string $ip, int $port): string
    {
        return 'ufw allow from ' . FirewallRule::ip($ip) . ' to any port ' . FirewallRule::port($port);
    }

    public function denyIncomingByDefault(): string
    {
        return 'ufw default deny incoming && ufw default allow outgoing';
    }

    public function status(): string
    {
        return 'ufw status verbose';
    }

    public function isActive(): string
    {
        return "ufw status | grep -q '^Status: active'";
    }

    public function enable(): string
    {
        return 'ufw --force enable';
    }
}
