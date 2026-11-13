<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Listeners;

use App\Events\DeploymentSucceeded;
use App\Jobs\CheckDomainReach;

final class CheckNewDomainsAfterDeploy
{
    public function handle(DeploymentSucceeded $event): void
    {
        $application = $event->deployment->application;

        if ($application === null) {
            return;
        }

        $readings = (array) $application->domain_reach;
        $unconfirmed = array_filter($application->allDomains(), fn(string $domain): bool => ($readings[$domain]['ready'] ?? null) !== true);

        if ($unconfirmed !== []) {
            CheckDomainReach::dispatch($application);
        }
    }
}
