<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Detection;

final readonly class Blocker
{
    public function __construct(
        public string $field,
        public string $reason,
        public string $fix = '',
    ) {}

    /**
     * @return array{field: string, reason: string, fix: string}
     */
    public function toArray(): array
    {
        return ['field' => $this->field, 'reason' => $this->reason, 'fix' => $this->fix];
    }
}
