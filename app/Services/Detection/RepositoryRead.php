<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Detection;

use App\Services\Runtime\RepositoryFacts;

final readonly class RepositoryRead
{
    /**
     * @param  array<string, list<string>>  $installed
     */
    public function __construct(
        public ?RepositoryFacts $facts,
        public ?string $commit = null,
        public ?string $failure = null,
        public bool $definitive = false,
        public array $installed = [],
    ) {}

    /**
     * @return list<string>
     */
    public function installed(string $runtime): array
    {
        return $this->installed[$runtime] ?? [];
    }

    public static function failed(string $failure, bool $definitive): self
    {
        return new self(null, null, $failure, $definitive);
    }
}
