<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Server;

use App\Services\Server\Contracts\PackageManager;

final class AptPackageManager implements PackageManager
{
    private const NON_INTERACTIVE = 'DEBIAN_FRONTEND=noninteractive';

    private const FAILED_SOURCE = '~^(?:[EW]: Failed to fetch|E: The repository \'|E: Release file for)\s*([a-z][a-z0-9+.-]*://[^\s\']+)~m';

    public static function failedSource(string $output): ?string
    {
        if (preg_match(self::FAILED_SOURCE, $output, $match) !== 1) {
            return null;
        }

        return preg_replace('~/dists/.*$~', '', $match[1]);
    }

    public function update(): string
    {
        return self::NON_INTERACTIVE . ' apt-get update -y';
    }

    public function install(array $packages): string
    {
        if ($packages === []) {
            return 'true';
        }

        return self::NON_INTERACTIVE . ' apt-get install -y --no-install-recommends ' . PackageList::escape($packages) . ' -o DPkg::Lock::Timeout=300';
    }

    public function isInstalled(string $package): string
    {
        return "dpkg-query -W -f='\${Status}' " . escapeshellarg($package) . " 2>/dev/null | grep -q 'ok installed'";
    }

    public function addRepository(string $repo): string
    {
        return self::NON_INTERACTIVE . ' add-apt-repository -y ' . escapeshellarg($repo);
    }
}
