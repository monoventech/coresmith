<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Listeners;

use App\Events\DeploymentFailed;
use App\Services\AutoDeploy\AutoDeploy;
use Illuminate\Contracts\Queue\ShouldQueue;

final class FollowUpAutoDeploy implements ShouldQueue
{
    public string $queue = 'default';

    public int $delay = 15;

    public int $tries = 1;

    public int $timeout = 30;

    public function handle(DeploymentFailed $event): void
    {
        rescue(fn() => app(AutoDeploy::class)->afterDeployment($event->deployment), report: true);
    }
}
