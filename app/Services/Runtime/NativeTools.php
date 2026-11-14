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

abstract class NativeTools
{
    abstract public function find(string $appDirectory): string;

    /**
     * @return array<string, array{apt: list<string>, dnf: list<string>}>
     */
    abstract protected function packages(): array;

    /**
     * @return array<string, list<string>>
     */
    public function needed(Server $server, string $found): array
    {
        $family = BuildDependencies::family($server->os_name);
        $names = preg_split('/\s+/', trim($found)) ?: [];

        if ($family === null) {
            return [];
        }

        return array_map(fn(array $packages): array => $packages[$family], array_intersect_key($this->packages(), array_flip($names)));
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
