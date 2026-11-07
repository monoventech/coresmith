<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Cloud;

final readonly class CloudPrice
{
    public function __construct(
        public string $monthly,
        public string $currency,
    ) {}

    public static function fromNumber(float|int|string $monthly, string $currency): self
    {
        return new self(number_format((float) $monthly, 2, '.', ''), $currency);
    }
}
