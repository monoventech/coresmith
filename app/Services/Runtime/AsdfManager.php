<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Runtime;

use App\Exceptions\InvalidRuntimeException;
use App\Models\Server;
use App\Services\Server\PackageManagerFactory;
use App\Services\SSH\Contracts\SSHTransport;
use App\Services\SSH\SSHConnection;
use Illuminate\Support\Sleep;

final class AsdfManager
{
    public const DATA_DIR = '/opt/asdf-data';

    public const INSTALL_DIR = self::DATA_DIR . '/installs';

    public const SYSTEM_USER = 'coresmith';

    public const PATH = AsdfInstaller::ASDF_DIR . '/bin:' . self::DATA_DIR . '/shims:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin';

    public const PLUGINS = ['php', 'nodejs', 'bun', 'deno', 'ruby'];

    public const DOWNLOAD_RETRIES = 2;

    public const INSTALL_SECONDS = 1800;

    private const DOWNLOAD_ERRORS = ['curl: (', 'Could not resolve host', 'Failed to download', 'Connection timed out', 'Connection reset', 'ECONNRESET', 'ETIMEDOUT', 'ENOTFOUND', 'EAI_AGAIN', 'unable to download', 'SSL_ERROR_SYSCALL'];

    public static function assertValid(string $runtime, string $version): void
    {
        if (!in_array($runtime, self::PLUGINS, true)) {
            throw InvalidRuntimeException::runtime($runtime);
        }

        if (preg_match('/^[A-Za-z0-9._-]+\z/', $version) !== 1) {
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
            $connection->execute(PackageManagerFactory::for($server)->install($dependencies), sudo: true, timeout: SSHTransport::PACKAGE_SECONDS)->throw();
        }

        $this->withDownloadRetries($connection, $this->asCoresmith('asdf plugin update "$CORESMITH_RUNTIME"', ['CORESMITH_RUNTIME' => $runtime]), SSHTransport::COMMAND_SECONDS);
        $this->withDownloadRetries($connection, $this->asdfCommand('install', $runtime, $version), self::INSTALL_SECONDS);
    }

    public function withDownloadRetries(SSHConnection $connection, string $command, int $timeout): void
    {
        for ($attempt = 0; ; $attempt++) {
            $result = $connection->execute($command, sudo: true, timeout: $timeout);

            if ($result->successful()) {
                return;
            }

            if ($attempt >= self::DOWNLOAD_RETRIES || !$this->downloadFailed($result->output)) {
                $result->throw();
            }

            Sleep::for(10)->seconds();
        }
    }

    private function downloadFailed(string $output): bool
    {
        foreach (self::DOWNLOAD_ERRORS as $needle) {
            if (str_contains($output, $needle)) {
                return true;
            }
        }

        return false;
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
        $path = escapeshellarg(self::INSTALL_DIR . '/' . $runtime);
        $result = $connection->execute("ls -1 {$path} 2>/dev/null || true");

        $versions = array_values(array_filter(
            array_map(trim(...), preg_split('/\R/', $result->output) ?: []),
            fn(string $version): bool => preg_match('/^\d[0-9A-Za-z.+-]*$/D', $version) === 1 && self::isValid($runtime, $version),
        ));

        sort($versions);

        return $versions;
    }

    private static function isValid(string $runtime, string $version): bool
    {
        try {
            self::assertValid($runtime, $version);
        } catch (InvalidRuntimeException) {
            return false;
        }

        return true;
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

        return $this->versionPath($runtime, $version) . '/bin/' . $binary;
    }

    public function fpmBinaryPath(string $phpVersion): string
    {
        return $this->versionPath('php', $phpVersion) . '/sbin/php-fpm';
    }

    public function versionPath(string $runtime, string $version): string
    {
        if ($version !== '') {
            self::assertValid($runtime, $version);
        }

        return self::INSTALL_DIR . "/{$runtime}/{$version}";
    }

    /**
     * @param  array<string, string>  $env
     */
    public function asCoresmith(string $command, array $env = []): string
    {
        return self::asSystemUser($command, $env);
    }

    /**
     * @param  array<string, string>  $env
     */
    public static function asSystemUser(string $command, array $env = []): string
    {
        $variables = [
            'HOME' => '/home/' . self::SYSTEM_USER,
            'ASDF_DIR' => AsdfInstaller::ASDF_DIR,
            'ASDF_DATA_DIR' => self::DATA_DIR,
            'PATH' => self::PATH,
            ...$env,
        ];

        $exports = '';

        foreach ($variables as $name => $value) {
            $exports .= $name . '=' . escapeshellarg($value) . ' ';
        }

        return 'sudo -u ' . self::SYSTEM_USER . ' env ' . $exports . 'bash -c ' . escapeshellarg('cd "$HOME" && ' . $command);
    }
}
