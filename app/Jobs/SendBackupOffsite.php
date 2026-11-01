<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\OffsiteUploadFailed;
use App\Models\BackupDestination;
use App\Models\ManagedDatabase;
use App\Services\Database\OffsiteBackups;
use App\Services\SSH\SSHConnectionManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Throwable;

final class SendBackupOffsite implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 1260;

    public function __construct(
        public readonly ManagedDatabase $database,
        public readonly string $dumpPath,
    ) {
        $this->onQueue('server-tasks');
    }

    public function handle(SSHConnectionManager $connections, OffsiteBackups $offsite): void
    {
        $destination = $this->database->team?->backupDestination;

        if (!$destination instanceof BackupDestination) {
            return;
        }

        $server = $this->database->server;
        $connection = $connections->for($server);

        try {
            $key = $offsite->send($connection, $this->database, $this->dumpPath, $destination);

            $this->database->forceFill(['last_offsite_at' => now(), 'last_offsite_key' => $key, 'last_offsite_error' => null])->save();

            $offsite->pruneDumps($connection, $this->database, $this->dumpPath);
        } catch (OffsiteUploadFailed $exception) {
            $this->database->forceFill(['last_offsite_error' => Str::limit($exception->getMessage(), 497)])->save();
        } finally {
            $connections->disconnect($server);
        }
    }

    public function failed(Throwable $exception): void
    {
        $this->database->forceFill([
            'last_offsite_error' => "The backup was not sent. Check that {$this->database->server?->name} is reachable, then back up again.",
        ])->save();
    }
}
