<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Detection;

use App\Services\Runtime\RepositoryFacts;

final readonly class RepositoryRead
{
    public function __construct(
        public ?RepositoryFacts $facts,
        public ?string $commit = null,
        public ?string $failure = null,
        public bool $definitive = false,
    ) {}

    public static function failed(string $failure, bool $definitive): self
    {
        return new self(null, null, $failure, $definitive);
    }
}
