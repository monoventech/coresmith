<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Address;

final readonly class ReachReport
{
    /**
     * @param  list<string>  $problems
     * @param  list<string>  $notes
     * @param  list<array{type: string, name: string, value: string, note: string|null}>  $records
     */
    public function __construct(
        public ?bool $ready,
        public array $problems = [],
        public array $notes = [],
        public array $records = [],
    ) {}
}
