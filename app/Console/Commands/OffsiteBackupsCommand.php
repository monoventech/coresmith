<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\DatabaseType;
use App\Enums\PlanFeature;
use App\Enums\ServerStatus;
use App\Jobs\BackupDatabase;
use App\Models\BackupDestination;
use App\Models\ManagedDatabase;
use App\Services\Billing\TeamBilling;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

final class OffsiteBackupsCommand extends Command
{
    protected $signature = 'coresmith:backup-databases';

    protected $description = 'Back up every MySQL and PostgreSQL database of teams that send backups to S3';

    public function handle(TeamBilling $billing): int
    {
        $queued = 0;
        $timestamp = now()->format('YmdHis');

        BackupDestination::query()->with('team')->lazyById()->each(function (BackupDestination $destination) use ($billing, $timestamp, &$queued): void {
            $team = $destination->team;

            if ($team === null || !$billing->status($team)->allows(PlanFeature::OffsiteBackups)) {
                return;
            }

            ManagedDatabase::query()
                ->where('team_id', $team->getKey())
                ->whereIn('type', [DatabaseType::Mysql, DatabaseType::Postgresql])
                ->whereHas('server', fn(Builder $server) => $server->where('status', ServerStatus::Active))
                ->each(function (ManagedDatabase $database) use ($timestamp, &$queued): void {
                    BackupDatabase::dispatch($database, $timestamp, offsite: true);
                    $queued++;
                });

            $destination->forceFill(['last_run_at' => now()])->save();
        });

        $this->info($queued === 1 ? 'Queued 1 database backup.' : "Queued {$queued} database backups.");

        return self::SUCCESS;
    }
}
