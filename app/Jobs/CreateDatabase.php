<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\DatabaseType;
use App\Exceptions\ExistingDatabaseServerRefused;
use App\Models\ManagedDatabase;
use App\Services\Database\DatabaseConnections;
use App\Services\Database\DatabaseManager;
use App\Services\Database\RedisPorts;
use App\Services\SSH\SSHConnectionManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Throwable;

final class CreateDatabase implements ShouldQueue
{
    use Queueable;

    public bool $deleteWhenMissingModels = true;

    public int $tries = 2;

    public const LOCK_WAIT_SECONDS = 30;

    public int $timeout = 1260;

    public function __construct(public readonly ManagedDatabase $database)
    {
        $this->onQueue('server-tasks');
    }

    public function handle(SSHConnectionManager $connections, DatabaseManager $databases, RedisPorts $redisPorts): void
    {
        $this->database->credentialsLock()->block(self::LOCK_WAIT_SECONDS, function () use ($connections, $databases, $redisPorts): void {
            $server = $this->database->server;
            $connection = $connections->for($server);

            try {
                $password = DatabaseManager::generatePassword();

                $external = $databases->ensureServerInstalled($connection, $this->database->type, $this->database);

                if ($this->database->type === DatabaseType::Redis && !$this->database->onSharedRedis()) {
                    $redisPorts->confirm($connection, $this->database);
                }

                $databases->create($connection, $this->database, $password);

                $this->database->forceFill(['db_password' => $password, 'credentials_shown_at' => null, 'is_external' => $external, 'status' => ManagedDatabase::READY, 'creation_error' => null])->save();
                app(DatabaseConnections::class)->refresh($this->database);
            } finally {
                $connections->disconnect($server);
            }
        });
    }

    public function failed(?Throwable $exception): void
    {
        $this->database->forceFill([
            'status' => ManagedDatabase::FAILED,
            'creation_error' => $exception instanceof ExistingDatabaseServerRefused ? Str::limit($exception->getMessage(), 490) : null,
        ])->save();

        activity()->performedOn($this->database)->log('database_creation_failed');
    }
}
