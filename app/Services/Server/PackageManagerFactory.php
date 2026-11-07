<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Server;

use App\Exceptions\UnsupportedOperatingSystemException;
use App\Models\Server;
use App\Services\Server\Contracts\PackageManager;

final class PackageManagerFactory
{
    public static function for(Server $server): PackageManager
    {
        return match ($server->os_name) {
            'ubuntu', 'debian' => new AptPackageManager,
            'rocky', 'almalinux', 'fedora' => new DnfPackageManager,
            'amzn' => new YumPackageManager,
            default => throw UnsupportedOperatingSystemException::forOs($server->os_name),
        };
    }
}
