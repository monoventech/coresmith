<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Updates\PanelHealth;
use Illuminate\Console\Command;

final class HealthCommand extends Command
{
    protected $signature = 'coresmith:health {--json : Print the result as JSON}';

    protected $description = "Check Coresmith's database, migrations, cache, queue, Horizon, interface, storage and keys";

    public function handle(PanelHealth $health): int
    {
        $checks = $health->checks();
        $ok = PanelHealth::healthy($checks);

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'ok' => $ok,
                'release' => basename(base_path()),
                'version' => (string) config('coresmith.version'),
                'checks' => $checks,
            ], JSON_UNESCAPED_SLASHES));

            return $ok ? self::SUCCESS : self::FAILURE;
        }

        foreach ($checks as $check) {
            $this->line(sprintf('%-4s  %-10s  %s', $check['status'], $check['name'], $check['detail']));
        }

        $this->newLine();
        $this->line($ok ? 'Coresmith is healthy.' : 'Coresmith is not healthy. Fix the checks marked fail, then run this again.');

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
