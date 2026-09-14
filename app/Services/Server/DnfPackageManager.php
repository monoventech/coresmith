<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Server;

use App\Services\Server\Contracts\PackageManager;

final class DnfPackageManager implements PackageManager
{
    public function update(): string
    {
        return 'dnf check-update -y || true';
    }

    public function install(array $packages): string
    {
        if ($packages === []) {
            return 'true';
        }

        return 'dnf install -y ' . PackageList::escape($packages);
    }

    public function isInstalled(string $package): string
    {
        return 'rpm -q ' . escapeshellarg($package) . ' >/dev/null 2>&1';
    }

    public function addRepository(string $repo): string
    {
        return 'dnf config-manager --add-repo ' . escapeshellarg($repo);
    }
}
