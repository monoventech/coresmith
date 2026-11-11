<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Database;

use Carbon\CarbonImmutable;

final readonly class Backup
{
    public function __construct(
        public string $stamp,
        public CarbonImmutable $at,
        public int $size,
        public ?string $path = null,
        public ?string $key = null,
    ) {}

    public static function at(string $stamp): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('YmdHis', $stamp, (string) config('app.timezone', 'UTC')) ?: CarbonImmutable::now();
    }

    public function onServer(): bool
    {
        return $this->path !== null;
    }

    public function inS3(): bool
    {
        return $this->key !== null;
    }
}
