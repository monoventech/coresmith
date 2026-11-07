<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Server;

use App\Services\Server\Contracts\FirewallManager;

final class FirewalldFirewallManager implements FirewallManager
{
    public function allow(int $port, string $protocol = 'tcp'): string
    {
        $rule = FirewallRule::port($port) . '/' . FirewallRule::protocol($protocol);

        return "firewall-cmd --permanent --add-port={$rule} && firewall-cmd --reload";
    }

    public function deny(int $port, string $protocol = 'tcp'): string
    {
        $rule = FirewallRule::port($port) . '/' . FirewallRule::protocol($protocol);

        return "firewall-cmd --permanent --remove-port={$rule} && firewall-cmd --reload";
    }

    public function allowFrom(string $ip, int $port): string
    {
        $source = FirewallRule::ip($ip);
        $target = FirewallRule::port($port);

        return 'firewall-cmd --permanent --add-rich-rule='
            . escapeshellarg("rule family=\"ipv4\" source address=\"{$source}\" port port=\"{$target}\" protocol=\"tcp\" accept")
            . ' && firewall-cmd --reload';
    }

    public function denyIncomingByDefault(): string
    {
        return 'systemctl enable --now firewalld && firewall-cmd --set-default-zone=public';
    }

    public function status(): string
    {
        return 'firewall-cmd --list-all';
    }

    public function enable(): string
    {
        return 'systemctl enable --now firewalld';
    }
}
