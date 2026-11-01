<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Cloud;

final readonly class CloudServerSpec
{
    public function __construct(
        public string $name,
        public string $region,
        public string $size,
        public string $image,
        public string $tag,
        public string $publicKey,
        public ?string $keyId,
    ) {}
}
