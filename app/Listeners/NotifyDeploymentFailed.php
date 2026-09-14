<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Listeners;

use App\Events\DeploymentFailed;
use App\Notifications\DeploymentFailedNotification;
use App\Notifications\Preferences;
use App\Notifications\TeamNotifier;

final class NotifyDeploymentFailed
{
    public function __construct(private readonly TeamNotifier $notifier) {}

    public function handle(DeploymentFailed $event): void
    {
        $this->notifier->send(
            $event->deployment->application?->team,
            Preferences::DEPLOY_FAILED,
            new DeploymentFailedNotification($event->deployment),
        );
    }
}
