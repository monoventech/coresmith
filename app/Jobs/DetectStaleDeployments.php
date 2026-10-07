<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ApplicationStatus;
use App\Enums\DeploymentStatus;
use App\Events\DeploymentFailed;
use App\Models\Deployment;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class DetectStaleDeployments implements ShouldQueue
{
    use Queueable;

    public const STALE_AFTER_MINUTES = 15;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct()
    {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $this->stale(Deployment::query())
            ->get()
            ->each(function (Deployment $deployment): void {
                $minutes = max(1, (int) $deployment->started_at?->diffInMinutes(now()));
                $reason = "No progress was reported for {$minutes} " . Str::plural('minute', $minutes) . ', so Coresmith stopped waiting for this deploy. Deploy again.';

                $failed = DB::transaction(function () use ($deployment, $reason): int {
                    $failed = $this->stale(Deployment::query()->whereKey($deployment->getKey()))->update([
                        'status' => DeploymentStatus::Failed->value,
                        'finished_at' => now(),
                        'log' => trim((string) $deployment->log . "\n" . $reason),
                        'failure_reason' => $reason,
                    ]);

                    if ($failed > 0) {
                        $application = $deployment->application;

                        $application?->forceFill([
                            'status' => $application->current_release === null ? ApplicationStatus::Failed : ApplicationStatus::Active,
                        ])->save();
                    }

                    return $failed;
                });

                if ($failed === 0) {
                    return;
                }

                Cache::lock('deploy:' . $deployment->application_id)->forceRelease();

                DeploymentFailed::dispatch($deployment->refresh());
            });
    }

    private function stale(Builder $query): Builder
    {
        return $query
            ->where('status', DeploymentStatus::Running->value)
            ->where(fn(Builder $stale) => $stale
                ->where('stale_after', '<', now())
                ->orWhere(fn(Builder $legacy) => $legacy
                    ->whereNull('stale_after')
                    ->where('started_at', '<', now()->subMinutes(self::STALE_AFTER_MINUTES))));
    }
}
