<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Enums;

enum MonitorKind: string
{
    case Disk = 'disk';
    case Memory = 'memory';
    case Cpu = 'cpu';
    case Load = 'load';
    case Apps = 'apps';
    case Cron = 'cron';

    public function defaultThreshold(): float
    {
        return match ($this) {
            self::Disk => 80.0,
            self::Memory => 95.0,
            self::Cpu => 90.0,
            self::Load => 1.5,
            self::Apps => 500.0,
            self::Cron => 0.0,
        };
    }

    public function defaultDuration(): int
    {
        return match ($this) {
            self::Disk => 0,
            self::Memory => 4,
            self::Cpu => 10,
            self::Load => 15,
            self::Apps => 2,
            self::Cron => 0,
        };
    }
}
