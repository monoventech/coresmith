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

final class BackupDatabase implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 600;

    public function __construct(
        private readonly ManagedDatabase $database,
        private readonly string $timestamp,
    ) {
        $this->onQueue('server-tasks');
    }

    public function handle(SSHConnectionManager $connections, DatabaseManager $databases): string
    {
        $server = $this->database->server;
        $connection = $connections->for($server);

        try {
            return $databases->backup($connection, $this->database, $this->timestamp);
        } finally {
            $connections->disconnect($server);
        }
    }
}
