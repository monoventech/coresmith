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

    public function update(): string
    {
        return self::NON_INTERACTIVE.' apt-get update -y';
    }

    public function install(array $packages): string
    {
        if ($packages === []) {
            return 'true';
        }

        return self::NON_INTERACTIVE.' apt-get install -y --no-install-recommends '.PackageList::escape($packages);
    }

    public function isInstalled(string $package): string
    {
        return "dpkg-query -W -f='\${Status}' ".escapeshellarg($package)." 2>/dev/null | grep -q 'ok installed'";
    }

    public function addRepository(string $repo): string
    {
        return self::NON_INTERACTIVE.' add-apt-repository -y '.escapeshellarg($repo);
    }
}
