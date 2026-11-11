<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Database;

use App\Exceptions\OffsiteListFailed;
use App\Models\BackupDestination;
use App\Models\ManagedDatabase;
use App\Services\SSH\SSHConnectionManager;
use Throwable;

final class DatabaseBackups
{
    public const LIST_SECONDS = 10;

    private const CHECK_S3 = 'Check the bucket name, region and keys in Settings, General.';

    public function __construct(
        private readonly SSHConnectionManager $connections,
        private readonly OffsiteBackups $offsite,
    ) {}

    public function list(ManagedDatabase $database): BackupListing
    {
        $found = [];
        $serverError = null;
        $s3Error = null;

        try {
            foreach ($this->onServer($database) as $dump) {
                $found[$dump['stamp']] = new Backup($dump['stamp'], Backup::at($dump['stamp']), $dump['size'], path: $dump['path']);
            }
        } catch (Throwable) {
            $serverError = "Coresmith couldn't reach {$database->server?->name} to list its backups.";
        }

        $destination = $database->team?->backupDestination;

        if ($destination instanceof BackupDestination && $this->offsite->covers($database)) {
            try {
                foreach ($this->offsite->listed($destination, $database) as $object) {
                    $known = $found[$object['stamp']] ?? null;
                    $found[$object['stamp']] = new Backup($object['stamp'], Backup::at($object['stamp']), $known?->size ?? $object['size'], path: $known?->path, key: $object['key']);
                }
            } catch (OffsiteListFailed $e) {
                $s3Error = "Coresmith couldn't list the copies in S3. {$e->getMessage()} " . self::CHECK_S3;
            } catch (Throwable) {
                $s3Error = "Coresmith couldn't list the copies in S3. " . self::CHECK_S3;
            }
        }

        krsort($found, SORT_STRING);

        return new BackupListing(array_values($found), $serverError, $s3Error);
    }

    public function find(ManagedDatabase $database, string $stamp): ?Backup
    {
        foreach ($this->list($database)->backups as $backup) {
            if ($backup->stamp === $stamp) {
                return $backup;
            }
        }

        return null;
    }

    /**
     * @return list<array{stamp: string, path: string, size: int}>
     */
    private function onServer(ManagedDatabase $database): array
    {
        $server = $database->server;
        $name = (string) $database->name;
        $folder = DatabaseManager::BACKUP_ROOT . '/' . $name;
        $connection = $this->connections->brief($server, self::LIST_SECONDS);

        try {
            $output = $connection->execute(
                'find ' . escapeshellarg($folder) . ' -maxdepth 1 -type f -name ' . escapeshellarg($name . '-*.sql.gz') . " -printf '%f\\t%s\\n' 2>/dev/null || true",
                sudo: true,
                timeout: self::LIST_SECONDS,
            )->output;
        } finally {
            $connection->disconnect();
        }

        $dumps = [];

        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            if (preg_match('/^' . preg_quote($name, '/') . '-(\d{14})\.sql\.gz\t(\d+)$/', trim($line), $match) === 1) {
                $dumps[] = ['stamp' => $match[1], 'path' => $folder . '/' . $name . '-' . $match[1] . '.sql.gz', 'size' => (int) $match[2]];
            }
        }

        return $dumps;
    }
}
