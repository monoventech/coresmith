<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Cloud;

use App\Services\Panel\Formats;

final readonly class CloudSize
{
    /**
     * @param  array<string, CloudPrice>  $prices
     * @param  list<string>|null  $regions
     */
    public function __construct(
        public string $id,
        public int $cpus,
        public int $memoryMb,
        public int $diskGb,
        public string $architecture,
        public ?CloudPrice $price,
        public array $prices = [],
        public ?array $regions = null,
    ) {}

    public function availableIn(string $region): bool
    {
        return $this->regions === null || in_array($region, $this->regions, true);
    }

    public function priceIn(string $region): ?CloudPrice
    {
        return $this->prices[$region] ?? $this->price;
    }

    public function label(): string
    {
        return Formats::memory($this->memoryMb) . ' · ' . $this->cpus . ' vCPU';
    }
}
