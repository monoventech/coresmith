<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Exceptions;

use InvalidArgumentException;

final class InvalidRuntimeException extends InvalidArgumentException
{
    public static function runtime(string $runtime): self
    {
        return new self("[{$runtime}] is not a runtime Coresmith manages.");
    }

    public static function version(string $version): self
    {
        return new self("[{$version}] is not a valid runtime version.");
    }
}
