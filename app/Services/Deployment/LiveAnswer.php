<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Deployment;

final readonly class LiveAnswer
{
    public function __construct(
        public string $url,
        public int $status,
        public ?string $detail = null,
    ) {}
}
