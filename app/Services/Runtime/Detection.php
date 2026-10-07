<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Runtime;

final readonly class Detection
{
    /**
     * @param  list<string>  $runtimes
     * @param  array<string, Requirement>  $requirements
     * @param  list<string>  $notes
     */
    public function __construct(
        public array $runtimes,
        public array $requirements,
        public ?JsTooling $tooling,
        public array $notes,
    ) {}
}
