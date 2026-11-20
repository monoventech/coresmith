<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Panel\PanelBackup;
use Illuminate\Console\Command;
use RuntimeException;

final class RestoreBackupCommand extends Command
{
    protected $signature = 'coresmith:restore {archive : The backup to put back} {--force : Replace the database without asking}';

    protected $description = "Replace Coresmith's database with the one in a backup";

    public function handle(PanelBackup $backups): int
    {
        $archive = (string) $this->argument('archive');

        if (!$this->option('force') && !$this->confirm("Replace Coresmith's database with the one in {$archive}?")) {
            return self::FAILURE;
        }

        try {
            $backups->restore($archive);
        } catch (RuntimeException $failed) {
            $this->error($failed->getMessage());

            return self::FAILURE;
        }

        activity()->withProperties(['archive' => basename($archive)])->log('panel_restored');
        $this->info("Restored Coresmith's database from {$archive}.");

        return self::SUCCESS;
    }
}
