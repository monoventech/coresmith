<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SSH\SSHConnection;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Activitylog\Models\Activity;

final class PruneActivityCommand extends Command
{
    public const CHUNK = 5000;

    protected $signature = 'coresmith:prune-activity
        {--ssh-days= : How many days of server command records to keep}
        {--days= : How many days of other activity to keep}';

    protected $description = 'Delete activity older than its retention window';

    public function handle(): int
    {
        $sshDays = (int) ($this->option('ssh-days') ?? config('coresmith.activity.ssh_command_days'));
        $days = (int) ($this->option('days') ?? config('activitylog.clean_after_days'));

        if ($sshDays < 1 || $days < 1) {
            $this->error('Keep at least one day of activity.');

            return self::FAILURE;
        }

        $commandCutoff = now()->subDays($sshDays);
        $cutoff = now()->subDays($days);
        $commands = $this->prune(Activity::query()->where('description', SSHConnection::COMMAND_ACTIVITY), $commandCutoff);
        $other = $this->prune(Activity::query(), $cutoff);

        $this->info("Pruned {$commands} server command records from before {$commandCutoff->toDateString()} and {$other} other entries from before {$cutoff->toDateString()}.");

        return self::SUCCESS;
    }

    private function prune(Builder $query, CarbonInterface $cutoff): int
    {
        $deleted = 0;

        do {
            $batch = (clone $query)->where('created_at', '<', $cutoff)->limit(self::CHUNK)->delete();
            $deleted += $batch;
        } while ($batch > 0);

        return $deleted;
    }
}
