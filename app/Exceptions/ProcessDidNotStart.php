<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Support\Str;
use RuntimeException;

final class ProcessDidNotStart extends RuntimeException
{
    public function __construct(
        public readonly string $unit,
        public readonly string $state,
        public readonly string $journal,
        public readonly string $advice,
    ) {
        $process = Str::afterLast($unit, '-');

        parent::__construct('The ' . ($process === 'fpm' ? 'PHP' : $process) . " process didn't start ({$state}). {$advice}");
    }
}
