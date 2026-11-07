<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Server;

use App\Exceptions\UnsupportedOperatingSystemException;
use App\Models\Server;
use App\Services\Server\Contracts\FirewallManager;

final class FirewallManagerFactory
{
    public static function for(Server $server): FirewallManager
    {
        return match ($server->os_name) {
            'ubuntu', 'debian' => new UfwFirewallManager,
            'rocky', 'almalinux', 'fedora', 'amzn' => new FirewalldFirewallManager,
            default => throw UnsupportedOperatingSystemException::forOs($server->os_name),
        };
    }

    public static function packageFor(Server $server): string
    {
        return match ($server->os_name) {
            'ubuntu', 'debian' => 'ufw',
            'rocky', 'almalinux', 'fedora', 'amzn' => 'firewalld',
            default => throw UnsupportedOperatingSystemException::forOs($server->os_name),
        };
    }
}
