<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Runtime;

use App\Models\Server;
use App\Services\Server\PackageManagerFactory;
use App\Services\SSH\SSHConnection;

final class AsdfManager
{
    public const DATA_DIR = '/opt/asdf-data';

    public const INSTALL_DIR = self::DATA_DIR.'/installs';

    public const SYSTEM_USER = 'coresmith';

    public const PLUGINS = ['php', 'nodejs', 'bun', 'deno', 'ruby'];

    public function isInstalled(SSHConnection $connection, string $runtime, string $version): bool
    {
        $path = escapeshellarg($this->versionPath($runtime, $version));

        return trim($connection->execute("test -d {$path} && echo yes || echo no")->output) === 'yes';
    }

    public function install(SSHConnection $connection, Server $server, string $runtime, string $version): void
    {
        if ($this->isInstalled($connection, $runtime, $version)) {
            return;
        }

        $dependencies = BuildDependencies::for($runtime, $server->os_name);

        if ($dependencies !== []) {
            $connection->execute(PackageManagerFactory::for($server)->install($dependencies), sudo: true)->throw();
        }

        $connection->execute($this->asCoresmith("asdf install {$runtime} {$version}"), sudo: true)->throw();
    }

    public function uninstall(SSHConnection $connection, string $runtime, string $version): void
    {
        $connection->execute($this->asCoresmith("asdf uninstall {$runtime} {$version}"), sudo: true)->throw();
    }

    /**
     * @return list<string>
     */
    public function installedVersions(SSHConnection $connection, string $runtime): array
    {
        $path = escapeshellarg(self::INSTALL_DIR.'/'.$runtime);
        $result = $connection->execute("ls -1 {$path} 2>/dev/null || true");

        $versions = array_values(array_filter(array_map(
            trim(...),
            preg_split('/\R/', $result->output) ?: [],
        )));

        sort($versions);

        return $versions;
    }

    /**
     * @param  list<string>  $inUse
     * @return list<string>
     */
    public function orphanedVersions(SSHConnection $connection, string $runtime, array $inUse): array
    {
        return array_values(array_diff($this->installedVersions($connection, $runtime), $inUse));
    }

    public function binaryPath(string $runtime, string $version, ?string $binary = null): string
    {
        $binary ??= $runtime === 'nodejs' ? 'node' : $runtime;

        return $this->versionPath($runtime, $version).'/bin/'.$binary;
    }

    public function fpmBinaryPath(string $phpVersion): string
    {
        return $this->versionPath('php', $phpVersion).'/sbin/php-fpm';
    }

    public function versionPath(string $runtime, string $version): string
    {
        return self::INSTALL_DIR."/{$runtime}/{$version}";
    }

    private function asCoresmith(string $command): string
    {
        return 'sudo -u '.self::SYSTEM_USER.' bash -lc '.escapeshellarg($command);
    }
}
