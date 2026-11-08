<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Deployment;

use App\Enums\Edition;
use App\Enums\ServerStatus;
use App\Models\Application;
use App\Services\Billing\TeamBilling;
use App\Services\Cloud\Tasks\CloudTask;

final class DeployGuard
{
    public function __construct(private readonly TeamBilling $billing) {}

    public function refusal(Application $application): ?string
    {
        $team = $application->team;

        if ($team?->moving_at !== null) {
            return 'This team is moving to your own panel, so Coresmith Cloud no longer deploys it.';
        }

        if ($application->server?->status === ServerStatus::Moving) {
            return 'This server is still being moved here, so nothing was deployed. Deploy once its move finishes.';
        }

        if (Edition::current()->isCloud() && $team !== null && $this->billing->status($team)->readOnly()) {
            return 'This team is read-only, so the push was not deployed.';
        }

        $server = $application->server;

        return $server === null ? null : CloudTask::refusal($server, 'Deploy');
    }
}
