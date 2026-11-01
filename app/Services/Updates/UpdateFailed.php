<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Updates;

use RuntimeException;
use Throwable;

final class UpdateFailed extends RuntimeException
{
    public function __construct(public readonly string $step, string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
