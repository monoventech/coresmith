<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Cloud\Tasks;

use App\Models\Server;
use App\Services\Cloud\Contracts\CloudApi;

interface CloudTaskHandler
{
    public function step(Server $server, CloudTask $task, CloudApi $api): StepResult;

    public function expired(Server $server, CloudTask $task, CloudApi $api): StepResult;

    public function finished(Server $server, CloudTask $task, CloudApi $api): void;

    public function failed(Server $server, CloudTask $task, ?CloudApi $api, string $reason): void;
}
