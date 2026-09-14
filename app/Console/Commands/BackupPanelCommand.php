<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Panel\PanelBackup;
use Illuminate\Console\Command;
use Illuminate\Support\Number;
use Throwable;

final class BackupPanelCommand extends Command
{
    protected $signature = 'coresmith:backup';

    protected $description = 'Back up the panel database and environment, keeping the last fourteen';

    public function handle(PanelBackup $backups): int
    {
        try {
            $backup = $backups->run();
        } catch (Throwable $e) {
            activity()->withProperties(['error' => mb_substr($e->getMessage(), 0, 300)])->log('panel_backup_failed');
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        activity()->withProperties(['bytes' => $backup['bytes']])->log('panel_backed_up');
        $this->info('Backed up to ' . $backup['path'] . ' (' . Number::fileSize($backup['bytes']) . ').');

        return self::SUCCESS;
    }
}
