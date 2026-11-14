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
     * @param  list<array{type: string, name: string, value: string, note: string|null}>  $records
     */
    public function __construct(
        public ?string $refusal,
        public array $notes = [],
        private array $problems = [],
        public array $records = [],
    ) {}

    /**
     * @return list<string>
     */
    public function problems(): array
    {
        return $this->problems !== [] ? $this->problems : ($this->refusal === null ? [] : [$this->refusal]);
    }
}
