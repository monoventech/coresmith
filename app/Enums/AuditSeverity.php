<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Enums;

enum AuditSeverity: string
{
    case Blocker = 'blocker';
    case Warning = 'warning';
    case Info = 'info';

    public function rank(): int
    {
        return match ($this) {
            self::Blocker => 0,
            self::Warning => 1,
            self::Info => 2,
        };
    }
}
