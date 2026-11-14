<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Runtime;

use App\Exceptions\RuntimeUnavailable;
use App\Services\SSH\Contracts\SSHTransport;
use App\Services\SSH\SSHConnection;

final class RuntimeBinaries
{
    public const REQUIRED = [
        'php' => ['bin/php', 'sbin/php-fpm'],
        'nodejs' => ['bin/node', 'bin/npm'],
        'bun' => ['bin/bun'],
        'deno' => ['bin/deno'],
        'ruby' => ['bin/ruby', 'bin/bundle'],
        'python' => ['bin/python'],
    ];

    public const COMPOSER_INSTALLER = 'https://getcomposer.org/installer';

    public const COMPOSER_SIGNATURE = 'https://composer.github.io/installer.sig';

    public function __construct(
        private readonly AsdfManager $asdf,
        private readonly Catalog $catalog,
    ) {}

    /**
     * @return list<string>
     */
    public function ensure(SSHConnection $connection, string $runtime, string $version): array
    {
        AsdfManager::assertValid($runtime, $version);

        $root = $this->asdf->versionPath($runtime, $version);
        $missing = [];

        foreach (self::REQUIRED[$runtime] ?? [] as $binary) {
            if ($connection->execute('test -x ' . escapeshellarg($root . '/' . $binary))->failed()) {
                $missing[] = basename($binary);
            }
        }

        if ($missing !== []) {
            throw RuntimeUnavailable::incomplete($this->catalog->label($runtime), $version, $missing);
        }

        return match ($runtime) {
            'php' => $this->composer($connection, $root, $version),
            'nodejs' => $this->corepack($connection, $root),
            'python' => $this->asdf->ensureUv($connection) ? ['uv: installed ' . $this->asdf->uvVersion() . ', checksum verified'] : [],
            default => [],
        };
    }

    public function installed(SSHConnection $connection, string $runtime, string $version, bool $needsCorepack = false): bool
    {
        AsdfManager::assertValid($runtime, $version);

        $root = $this->asdf->versionPath($runtime, $version);
        $binaries = self::REQUIRED[$runtime] ?? [];

        if ($runtime === 'php') {
            $binaries[] = 'bin/composer';
        }

        if ($needsCorepack) {
            $binaries[] = 'bin/corepack';
        }

        $checks = array_map(fn(string $binary): string => 'test -x ' . escapeshellarg($root . '/' . $binary), $binaries);

        if ($runtime === 'python') {
            $checks[] = 'test -x ' . escapeshellarg($this->asdf->uvPath());
        }

        return $connection->execute(implode(' && ', $checks))->successful();
    }

    public function runtimeInstalled(SSHConnection $connection, string $runtime, string $version): bool
    {
        AsdfManager::assertValid($runtime, $version);

        $root = $this->asdf->versionPath($runtime, $version);
        $checks = array_map(fn(string $binary): string => 'test -x ' . escapeshellarg($root . '/' . $binary), self::REQUIRED[$runtime] ?? []);

        return $connection->execute(implode(' && ', $checks))->successful();
    }

    /**
     * @return list<string>
     */
    private function composer(SSHConnection $connection, string $root, string $version): array
    {
        $notes = [];

        if ($connection->execute('test -x ' . escapeshellarg($root . '/bin/composer'))->failed()) {
            $this->asdf->withDownloadRetries($connection, $this->asdf->asRuntimeUser($connection, $this->composerScript(), [
                'CORESMITH_PHP' => $root . '/bin/php',
                'CORESMITH_BIN' => $root . '/bin',
            ]), SSHTransport::COMMAND_SECONDS);

            $notes[] = 'composer: installed beside PHP ' . $version . ', installer checksum verified';
        }

        $modules = $connection->execute(escapeshellarg($root . '/bin/php') . ' -m 2>/dev/null')->output;
        $extensions = array_filter(array_map(trim(...), preg_split('/\r\n|\n|\r/', $modules) ?: []), fn(string $module): bool => $module !== '' && !str_starts_with($module, '['));

        $notes[] = 'php extensions: ' . implode(' ', $extensions);

        return $notes;
    }

    private function composerScript(): string
    {
        return implode(' && ', [
            'CORESMITH_COMPOSER_TMP="$(mktemp -d "${TMPDIR:-/tmp}/coresmith-composer.XXXXXXXX")"',
            'trap \'rm -rf "$CORESMITH_COMPOSER_TMP"\' EXIT',
            'cd "$CORESMITH_COMPOSER_TMP"',
            'curl -fsSL ' . self::COMPOSER_SIGNATURE . ' -o installer.sig',
            'curl -fsSL ' . self::COMPOSER_INSTALLER . ' -o composer-setup.php',
            '{ [ "$(cat installer.sig)" = "$("$CORESMITH_PHP" -r "echo hash_file(\'sha384\', \'composer-setup.php\');")" ] || { echo \'The Composer installer did not match its published checksum, so it was not run.\'; exit 1; }; }',
            '"$CORESMITH_PHP" composer-setup.php --quiet --install-dir="$CORESMITH_BIN" --filename=composer',
        ]);
    }

    /**
     * @return list<string>
     */
    private function corepack(SSHConnection $connection, string $root): array
    {
        if ($connection->execute('test -x ' . escapeshellarg($root . '/bin/corepack'))->successful()) {
            return [];
        }

        $this->asdf->withDownloadRetries($connection, $this->asdf->asRuntimeUser($connection, 'PATH="$CORESMITH_BIN:$PATH" npm install -g corepack', [
            'CORESMITH_BIN' => $root . '/bin',
        ]), SSHTransport::COMMAND_SECONDS);

        return ['corepack: installed, because this Node.js version no longer ships it'];
    }
}
