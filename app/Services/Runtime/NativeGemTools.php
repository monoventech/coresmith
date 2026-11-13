<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Runtime;

use App\Models\Server;
use App\Services\Server\PackageManagerFactory;
use App\Services\SSH\Contracts\SSHTransport;
use App\Services\SSH\SSHConnection;

final class NativeGemTools
{
    private const GEMS = [
        'rb_sys' => ['apt' => ['libclang-dev'], 'dnf' => ['clang-devel']],
        'rugged' => ['apt' => ['cmake'], 'dnf' => ['cmake']],
    ];

    public function find(string $lockfile): string
    {
        return 'grep -ohE ' . escapeshellarg('^    (' . implode('|', array_keys(self::GEMS)) . ') \(') . ' ' . escapeshellarg($lockfile) . " 2>/dev/null | awk '{print \$1}' | sort -u";
    }

    /**
     * @return array<string, list<string>>
     */
    public function needed(Server $server, string $found): array
    {
        $family = BuildDependencies::family($server->os_name);
        $gems = preg_split('/\s+/', trim($found)) ?: [];

        if ($family === null) {
            return [];
        }

        return array_map(fn(array $packages): array => $packages[$family], array_intersect_key(self::GEMS, array_flip($gems)));
    }

    /**
     * @param  list<string>  $packages
     * @return list<string>
     */
    public function missing(SSHConnection $connection, array $packages): array
    {
        if ($packages === []) {
            return [];
        }

        $manager = PackageManagerFactory::for($connection->server());
        $output = $connection->execute(implode('; ', array_map(fn(string $package): string => '{ ' . $manager->isInstalled($package) . '; } || echo ' . escapeshellarg($package), $packages)), sudo: true)->output;

        return array_values(array_intersect($packages, preg_split('/\s+/', trim($output)) ?: []));
    }

    /**
     * @param  list<string>  $packages
     */
    public function install(SSHConnection $connection, array $packages): void
    {
        $manager = PackageManagerFactory::for($connection->server());

        $connection->execute($manager->update(), sudo: true, timeout: SSHTransport::PACKAGE_SECONDS)->throw();
        $connection->execute($manager->install($packages), sudo: true, timeout: SSHTransport::PACKAGE_SECONDS)->throw();
    }
}
