<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ManagedDatabase;
use App\Services\Database\DatabaseManager;
use App\Services\SSH\SSHConnectionManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class CreateDatabase implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 120;

    public function __construct(private readonly ManagedDatabase $database)
    {
        $this->onQueue('server-tasks');
    }

    public function handle(SSHConnectionManager $connections, DatabaseManager $databases): void
    {
        $server = $this->database->server;
        $connection = $connections->for($server);

        try {
            $password = DatabaseManager::generatePassword();

            $external = $databases->ensureServerInstalled($connection, $this->database->type, $this->database);
            $databases->create($connection, $this->database, $password);

            $this->database->forceFill(['db_password' => $password, 'is_external' => $external])->save();
        } finally {
            $connections->disconnect($server);
        }
    }
}
