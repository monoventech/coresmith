<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\DeploymentStatus;
use App\Events\DeploymentFailed;
use App\Models\Deployment;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

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
        $cutoff = now()->subMinutes(self::STALE_AFTER_MINUTES);

        Deployment::query()
            ->where('status', DeploymentStatus::Running->value)
            ->where('started_at', '<', $cutoff)
            ->each(function (Deployment $deployment): void {
                $deployment->forceFill([
                    'status' => DeploymentStatus::Failed,
                    'finished_at' => now(),
                    'log' => trim((string) $deployment->log."\nDeployment timed out (no response in ".self::STALE_AFTER_MINUTES.' minutes). Likely SSH connection failure.'),
                ])->save();

                Cache::lock('deploy:'.$deployment->application_id)->forceRelease();

                DeploymentFailed::dispatch($deployment);
            });
    }
}
