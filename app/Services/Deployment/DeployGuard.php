<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Deployment;

use App\Models\Application;
use App\Services\Cloud\Tasks\CloudTask;

final class DeployGuard
{
    public function refusal(Application $application): ?string
    {
        $server = $application->server;

        return $server === null ? null : CloudTask::refusal($server, 'Deploy');
    }
}
