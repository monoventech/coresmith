<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Enums;

enum AppSize: string
{
    case Small = 'small';
    case Medium = 'medium';
    case Large = 'large';
    case Custom = 'custom';

    public function maxChildren(): int
    {
        return match ($this) {
            self::Small, self::Custom => 5,
            self::Medium => 15,
            self::Large => 30,
        };
    }

    public function workers(): int
    {
        return match ($this) {
            self::Small, self::Custom => 1,
            self::Medium => 2,
            self::Large => 4,
        };
    }

    public function memoryMaxMb(): int
    {
        return match ($this) {
            self::Small, self::Custom => 256,
            self::Medium => 512,
            self::Large => 1024,
        };
    }
}
