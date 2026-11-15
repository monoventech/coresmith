<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Monitors;

use App\Enums\MonitorKind;

final readonly class MonitorLimit
{
    public function __construct(
        public MonitorKind $kind,
        public float $threshold,
        public int $duration,
        public bool $enabled,
        public string $source,
    ) {}
}
