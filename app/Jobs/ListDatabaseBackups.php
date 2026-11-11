<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ManagedDatabase;
use App\Services\Database\DatabaseBackups;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class ListDatabaseBackups implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public bool $deleteWhenMissingModels = true;

    public int $tries = 1;

    public int $timeout = 60;

    public int $uniqueFor = 120;

    public function __construct(public readonly ManagedDatabase $database)
    {
        $this->onQueue('server-tasks');
    }

    public function uniqueId(): string
    {
        return (string) $this->database->getKey();
    }

    public function handle(DatabaseBackups $backups): void
    {
        $backups->refresh($this->database);
    }
}
