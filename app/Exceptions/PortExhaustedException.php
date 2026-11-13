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
        return new self("No port block is free on {$name}. Its apps, apps removed in the last 7 days or other processes hold all 200 blocks in 8000–9999. Remove apps you no longer need or stop processes using ports in that range. A removed app's block frees up after 7 days.");
    }

    public static function ssrForServer(string $name): self
    {
        return new self("No available SSR ports on server [{$name}].");
    }
}
