<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Address;

final readonly class AddressVerdict
{
    /**
     * @param  list<string>  $notes
     * @param  list<string>  $problems
     */
    public function __construct(
        public ?string $refusal,
        public array $notes = [],
        private array $problems = [],
    ) {}

    /**
     * @return list<string>
     */
    public function problems(): array
    {
        return $this->problems !== [] ? $this->problems : ($this->refusal === null ? [] : [$this->refusal]);
    }
}
