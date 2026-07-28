<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Runtime;

use App\Exceptions\InvalidRuntimeException;
use App\Models\Server;
use App\Services\Server\PackageManagerFactory;
use App\Services\SSH\SSHConnection;

final class AsdfManager
{
    public const DATA_DIR = '/opt/asdf-data';

    public const INSTALL_DIR = self::DATA_DIR.'/installs';

    public const SYSTEM_USER = 'coresmith';

    public const PLUGINS = ['php', 'nodejs', 'bun', 'deno', 'ruby'];

    public static function assertValid(string $runtime, string $version): void
    {
        if (!in_array($runtime, self::PLUGINS, true)) {
            throw InvalidRuntimeException::runtime($runtime);
        }

        if (preg_match('/^[A-Za-z0-9._-]+$/', $version) !== 1) {
            throw InvalidRuntimeException::version($version);
        }
    }

    public function isInstalled(SSHConnection $connection, string $runtime, string $version): bool
    {
        self::assertValid($runtime, $version);

        $path = escapeshellarg($this->versionPath($runtime, $version));

        return trim($connection->execute("test -d {$path} && echo yes || echo no")->output) === 'yes';
    }

    public function install(SSHConnection $connection, Server $server, string $runtime, string $version): void
    {
        self::assertValid($runtime, $version);

        if ($this->isInstalled($connection, $runtime, $version)) {
            return;
        }

        $dependencies = BuildDependencies::for($runtime, $server->os_name);

        if ($dependencies !== []) {
            $connection->execute(PackageManagerFactory::for($server)->install($dependencies), sudo: true)->throw();
        }

        $connection->execute($this->asdfCommand('install', $runtime, $version), sudo: true)->throw();
    }

    public function uninstall(SSHConnection $connection, string $runtime, string $version): void
    {
        self::assertValid($runtime, $version);

        $connection->execute($this->asdfCommand('uninstall', $runtime, $version), sudo: true)->throw();
    }

    private function asdfCommand(string $action, string $runtime, string $version): string
    {
        return $this->asCoresmith("asdf {$action} \"\$CORESMITH_RUNTIME\" \"\$CORESMITH_VERSION\"", [
            'CORESMITH_RUNTIME' => $runtime,
            'CORESMITH_VERSION' => $version,
        ]);
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

    /**
     * @param  array<string, string>  $env
     */
    private function asCoresmith(string $command, array $env = []): string
    {
        $exports = '';

        foreach ($env as $name => $value) {
            $exports .= $name.'='.escapeshellarg($value).' ';
        }

        return 'sudo -u '.self::SYSTEM_USER.' env '.$exports.'bash -lc '.escapeshellarg($command);
    }
}
