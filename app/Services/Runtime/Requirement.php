<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Runtime;

final readonly class Requirement
{
    public function __construct(
        public string $runtime,
        public string $constraint,
        public string $raw,
        public string $source,
        public string $field,
        public string $flavour,
    ) {}
}
