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

        $host = parse_url((string) config('app.url'), PHP_URL_HOST);
        $confirmation = is_string($host) && $host !== '' ? $host : 'uninstall';
        $script = base_path('scripts/uninstall.sh');

        $this->line('This removes the Coresmith panel from this server:');
        $this->line('  - its services, its Caddy site, PHP-FPM pool and fail2ban jail');
        $this->line("  - /opt/coresmith, /var/lib/coresmith, the panel's logs and the coresmith user");
        $this->line('A copy of its .env, backups and SQLite database is saved under /root first. Apps deployed on this machine keep running.');
        $this->newLine();

        if ($this->ask("Type {$confirmation} to remove Coresmith from this server") !== $confirmation) {
            $this->error('Nothing was removed.');

            return self::FAILURE;
        }

        $reason = $handOff->replaceWith('/bin/bash', [$script, '--yes']);

        $this->error("Coresmith couldn't start the uninstaller: {$reason}. Run sudo bash {$script} --yes instead.");

        return self::FAILURE;
    }
}
