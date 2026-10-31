<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Events;

use App\Models\Server;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class ServerProvisioningFailed
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Server $server,
        public readonly int $phase,
        public readonly string $phaseName,
    ) {}
}
