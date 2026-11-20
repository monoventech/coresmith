<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Panel\PanelBackup;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use RuntimeException;

final class RestoreBackupCommand extends Command
{
    public const REFRESH = ['config:cache', 'horizon:terminate', 'reverb:restart'];

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

        foreach (self::REFRESH as $command) {
            if (Process::path(base_path())->timeout(120)->run([PHP_BINARY, 'artisan', $command])->failed()) {
                $this->warn("Coresmith couldn't run {$command}. Run it yourself as the coresmith user, from " . base_path() . '.');
            }
        }

        $this->info("Restored Coresmith's database from {$archive}. Coresmith now uses the keys in its .env.");

        return self::SUCCESS;
    }
}
