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
        return new self("No port block is free on {$name}. All 200 blocks in 8000–9999 are taken by apps or by other processes. Remove apps you no longer need, or stop processes using ports in that range.");
    }

    public static function ssrForServer(string $name): self
    {
        return new self("No available SSR ports on server [{$name}].");
    }
}
