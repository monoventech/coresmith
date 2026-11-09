<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\DatabaseType;
use App\Models\ManagedDatabase;
use App\Services\Database\DatabaseManager;
use App\Services\Database\RedisPorts;
use App\Services\SSH\SSHConnectionManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class CreateDatabase implements ShouldQueue
{
    use Queueable;

    public bool $deleteWhenMissingModels = true;

    public int $tries = 2;

    public const LOCK_WAIT_SECONDS = 30;

    public int $timeout = 1260;

    public function __construct(private readonly ManagedDatabase $database)
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

                $this->database->forceFill(['db_password' => $password, 'credentials_shown_at' => null, 'is_external' => $external])->save();
            } finally {
                $connections->disconnect($server);
            }
        });
    }
}
