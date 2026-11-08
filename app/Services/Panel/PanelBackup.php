<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Panel;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use PharData;
use RuntimeException;
use Throwable;

final class PanelBackup
{
    public const KEEP = 14;

    public const PREFIX = 'coresmith-';

    public function directory(): string
    {
        return storage_path('app/private/backups');
    }

    /**
     * @return array{path: string, bytes: int}
     */
    public function run(): array
    {
        File::ensureDirectoryExists($this->directory(), 0700);

        $stamp = now()->format('Ymd-His');
        $work = $this->directory() . '/' . self::PREFIX . $stamp;
        File::ensureDirectoryExists($work, 0700);

        try {
            $this->dumpDatabase($work);

            if (is_file(base_path('.env'))) {
                File::copy(base_path('.env'), $work . '/.env');
            }

            $tar = $work . '.tar';
            $archive = new PharData($tar);
            $archive->buildFromDirectory($work);
            $archive->compress(\Phar::GZ);

            unset($archive);
            File::delete($tar);
            chmod($tar . '.gz', 0600);

            $this->prune();

            return ['path' => $tar . '.gz', 'bytes' => (int) filesize($tar . '.gz')];
        } finally {
            File::deleteDirectory($work);
        }
    }

    /**
     * @return array{at: CarbonImmutable, bytes: int, verified: bool}|null
     */
    public function latest(): ?array
    {
        $files = glob($this->directory() . '/' . self::PREFIX . '*.tar.gz') ?: [];
        rsort($files);

        $latest = $files[0] ?? null;

        if ($latest === null) {
            return null;
        }

        return [
            'at' => CarbonImmutable::createFromTimestamp((int) filemtime($latest)),
            'bytes' => (int) filesize($latest),
            'verified' => $this->verify($latest),
        ];
    }

    public function verify(string $path): bool
    {
        try {
            $entries = iterator_to_array(new \RecursiveIteratorIterator(new PharData($path)), false);

            return count($entries) > 0;
        } catch (Throwable) {
            return false;
        }
    }

    public function prune(): void
    {
        $files = glob($this->directory() . '/' . self::PREFIX . '*.tar.gz') ?: [];
        rsort($files);

        foreach (array_slice($files, self::KEEP) as $old) {
            File::delete($old);
        }
    }

    public function restore(string $archive): void
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();
        $config = $connection->getConfig();
        $entry = $driver === 'sqlite' ? 'database.sqlite' : 'database.sql';

        $unreadable = new RuntimeException("The backup at {$archive} can't be read, so it can't be restored.");

        if (!is_file($archive)) {
            throw $unreadable;
        }

        try {
            $phar = new PharData($archive);
            $entries = array_map(fn(\PharFileInfo $file): string => $file->getFilename(), iterator_to_array(new \RecursiveIteratorIterator($phar), false));
        } catch (Throwable) {
            throw $unreadable;
        }

        if (!in_array($entry, $entries, true)) {
            throw new RuntimeException("The backup at {$archive} has no database in it.");
        }

        match ($driver) {
            'sqlite' => $this->restoreSqlite($phar, (string) $config['database']),
            'pgsql', 'mysql', 'mariadb' => $this->restoreExternal($phar, $driver, $config, $archive),
            default => throw new RuntimeException('Backups support Coresmith on SQLite, PostgreSQL and MySQL.'),
        };
    }

    private function restoreSqlite(PharData $phar, string $live): void
    {
        DB::disconnect();

        $work = dirname($live) . '/.restore-' . Str::random(12);
        File::ensureDirectoryExists($work, 0700);

        try {
            $phar->extractTo($work, 'database.sqlite', true);

            if (!@rename($work . '/database.sqlite', $live)) {
                throw new RuntimeException("Coresmith couldn't put the restored database at {$live}.");
            }

            foreach (['-wal', '-shm', '-journal'] as $suffix) {
                File::delete($live . $suffix);
            }

            chmod($live, 0600);
        } finally {
            File::deleteDirectory($work);
        }
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function restoreExternal(PharData $phar, string $driver, array $config, string $archive): void
    {
        $work = storage_path('app/private/restore-' . Str::random(12));
        File::ensureDirectoryExists($work, 0700);

        try {
            $phar->extractTo($work, 'database.sql', true);
            $file = $work . '/database.sql';
            $database = (string) $config['database'];

            if ($driver === 'pgsql') {
                $this->load(['psql', '--quiet', '--single-transaction', '--set=ON_ERROR_STOP=1', '--command=DROP OWNED BY CURRENT_USER', '--file=' . $file, '--host=' . $config['host'], '--port=' . $config['port'], '--username=' . $config['username'], $database], ['PGPASSWORD' => (string) $config['password']]);

                return;
            }

            $quoted = '`' . str_replace('`', '``', $database) . '`';
            $client = ['mysql', '--host=' . $config['host'], '--port=' . $config['port'], '--user=' . $config['username']];
            $environment = ['MYSQL_PWD' => (string) $config['password']];
            $dump = filesize($file) > 0 ? fopen($file, 'rb') : false;

            if ($dump === false) {
                throw new RuntimeException("The database in the backup at {$archive} is empty or can't be read, so nothing was restored.");
            }

            try {
                $this->load([...$client, "--execute=DROP DATABASE IF EXISTS {$quoted}; CREATE DATABASE {$quoted} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"], $environment);
                $this->load([...$client, $database], $environment, $dump);
            } finally {
                fclose($dump);
            }
        } finally {
            File::deleteDirectory($work);
        }
    }

    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $environment
     * @param  resource|null  $input
     */
    private function load(array $command, array $environment, $input = null): void
    {
        $result = Process::env($environment)->timeout(1800)->input($input)->run($command);

        if ($result->failed()) {
            throw new RuntimeException('Restoring the database failed: ' . trim($result->errorOutput()));
        }
    }

    private function dumpDatabase(string $work): void
    {
        $connection = DB::connection();
        $config = $connection->getConfig();

        match ($connection->getDriverName()) {
            'sqlite' => $connection->statement('VACUUM INTO ?', [$work . '/database.sqlite']),
            'pgsql' => $this->external(['pg_dump', '--no-owner', '--clean', '--if-exists', '--file=' . $work . '/database.sql', '--host=' . $config['host'], '--port=' . $config['port'], '--username=' . $config['username'], $config['database']], ['PGPASSWORD' => (string) $config['password']]),
            'mysql', 'mariadb' => $this->external(['mysqldump', '--single-transaction', '--result-file=' . $work . '/database.sql', '--host=' . $config['host'], '--port=' . $config['port'], '--user=' . $config['username'], $config['database']], ['MYSQL_PWD' => (string) $config['password']]),
            default => throw new RuntimeException('Backups support Coresmith on SQLite, PostgreSQL and MySQL.'),
        };
    }

    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $environment
     */
    private function external(array $command, array $environment): void
    {
        $result = Process::env($environment)->timeout(600)->run($command);

        if ($result->failed()) {
            throw new RuntimeException('The database dump failed: ' . trim($result->errorOutput()));
        }
    }
}
