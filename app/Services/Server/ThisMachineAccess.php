<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Server;

final readonly class ThisMachineAccess
{
    public function __construct(
        public int $port,
        public string $fingerprint,
    ) {}
}
