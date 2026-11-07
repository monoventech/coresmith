<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Enums;

use InvalidArgumentException;

enum Edition: string
{
    case SelfHosted = 'self-hosted';
    case Cloud = 'cloud';

    public static function current(): self
    {
        $value = config('coresmith.edition');

        return self::tryFrom(is_string($value) ? $value : '')
            ?? throw new InvalidArgumentException('CORESMITH_EDITION must be self-hosted or cloud, not ' . (is_scalar($value) ? (string) $value : get_debug_type($value)) . '.');
    }

    public function isCloud(): bool
    {
        return $this === self::Cloud;
    }

    public function isSelfHosted(): bool
    {
        return $this === self::SelfHosted;
    }

    public function label(): string
    {
        return match ($this) {
            self::SelfHosted => 'Self-hosted',
            self::Cloud => 'Coresmith Cloud',
        };
    }
}
