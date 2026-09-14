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

    private function dumpDatabase(string $work): void
    {
        $connection = DB::connection();
        $config = $connection->getConfig();

        match ($connection->getDriverName()) {
            'sqlite' => $connection->statement('VACUUM INTO ?', [$work . '/database.sqlite']),
            'pgsql' => $this->external(['pg_dump', '--no-owner', '--file=' . $work . '/database.sql', '--host=' . $config['host'], '--port=' . $config['port'], '--username=' . $config['username'], $config['database']], ['PGPASSWORD' => (string) $config['password']]),
            'mysql', 'mariadb' => $this->external(['mysqldump', '--single-transaction', '--result-file=' . $work . '/database.sql', '--host=' . $config['host'], '--port=' . $config['port'], '--user=' . $config['username'], $config['database']], ['MYSQL_PWD' => (string) $config['password']]),
            default => throw new RuntimeException('Backups support SQLite, PostgreSQL and MySQL panels.'),
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
