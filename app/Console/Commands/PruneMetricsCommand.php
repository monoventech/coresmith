<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ServerMetric;
use Illuminate\Console\Command;

final class PruneMetricsCommand extends Command
{
    public const DEFAULT_DAYS = 30;

    public const CHUNK = 5000;

    protected $signature = 'coresmith:prune-metrics {--days= : How many days of metrics to keep}';

    protected $description = 'Delete server metrics older than the retention window';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? self::DEFAULT_DAYS);

        if ($days < 1) {
            $this->error('Keep at least one day of metrics.');

            return self::FAILURE;
        }

        $cutoff = now()->subDays($days);
        $deleted = 0;

        do {
            $batch = ServerMetric::query()
                ->where('recorded_at', '<', $cutoff)
                ->limit(self::CHUNK)
                ->delete();

            $deleted += $batch;
        } while ($batch > 0);

        $this->info("Pruned {$deleted} metrics recorded before {$cutoff->toDateTimeString()}.");

        return self::SUCCESS;
    }
}
