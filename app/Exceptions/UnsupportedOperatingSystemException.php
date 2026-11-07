<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class UnsupportedOperatingSystemException extends RuntimeException
{
    public static function forOs(?string $osName): self
    {
        $name = $osName === null || $osName === '' ? 'unknown' : $osName;

        return new self("Coresmith does not support the [{$name}] distribution. Supported: Ubuntu, Debian, Amazon Linux, Rocky Linux, AlmaLinux, Fedora.");
    }

    public static function missingSystemd(): self
    {
        return new self('Coresmith requires systemd, which was not found on this server.');
    }
}
