<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Server;

final readonly class OsInfo
{
    public function __construct(
        public string $name,
        public string $version,
        public int $cpuCores,
        public int $memoryMb,
        public int $diskGb,
    ) {}

    /**
     * @return array{cpu_cores: int, memory_mb: int, disk_gb: int}
     */
    public function toMeta(): array
    {
        return [
            'cpu_cores' => $this->cpuCores,
            'memory_mb' => $this->memoryMb,
            'disk_gb' => $this->diskGb,
        ];
    }
}
