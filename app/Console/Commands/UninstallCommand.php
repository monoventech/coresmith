<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Install\ProcessHandOff;
use Illuminate\Console\Command;

final class UninstallCommand extends Command
{
    protected $signature = 'coresmith:uninstall';

    protected $description = 'Remove Coresmith from this server';

    public function handle(ProcessHandOff $handOff): int
    {
        if (!$handOff->runningAsRoot()) {
            $this->error('Run this as root: sudo php artisan coresmith:uninstall');

            return self::FAILURE;
        }

        $script = base_path('scripts/uninstall.sh');
        $reason = $handOff->replaceWith('/bin/bash', [$script]);

        $this->error("Coresmith couldn't start the uninstaller: {$reason}. Run sudo bash {$script} instead.");

        return self::FAILURE;
    }
}
