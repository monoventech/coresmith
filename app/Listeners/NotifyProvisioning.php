<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Listeners;

use App\Events\ServerProvisioned;
use App\Events\ServerProvisioningFailed;
use App\Notifications\Preferences;
use App\Notifications\ProvisioningFailedNotification;
use App\Notifications\ServerProvisionedNotification;
use App\Notifications\TeamNotifier;

final class NotifyProvisioning
{
    public function __construct(private readonly TeamNotifier $notifier) {}

    public function handle(ServerProvisioned|ServerProvisioningFailed $event): void
    {
        rescue(fn() => $this->notifier->send(
            $event->server->team,
            Preferences::PROVISIONING,
            $event instanceof ServerProvisioned
                ? new ServerProvisionedNotification($event->server, $event->durationSeconds)
                : new ProvisioningFailedNotification($event->server, $event->phase, $event->phaseName),
        ), report: true);
    }
}
