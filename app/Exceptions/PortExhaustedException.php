<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class PortExhaustedException extends RuntimeException
{
    public static function forServer(string $name): self
    {
        return new self("No available port blocks on server [{$name}].");
    }

    public static function ssrForServer(string $name): self
    {
        return new self("No available SSR ports on server [{$name}].");
    }
}
