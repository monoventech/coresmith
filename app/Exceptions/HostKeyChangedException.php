<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class HostKeyChangedException extends RuntimeException
{
    private string $expectedFingerprint = '';

    private string $actualFingerprint = '';

    public static function forServer(string $ipAddress, string $expected, string $actual): self
    {
        $exception = new self(
            "Host key for {$ipAddress} has changed. This could indicate a man-in-the-middle attack or a server reinstall."
        );

        $exception->expectedFingerprint = $expected;
        $exception->actualFingerprint = $actual;

        return $exception;
    }

    public function expected(): string
    {
        return $this->expectedFingerprint;
    }

    public function actual(): string
    {
        return $this->actualFingerprint;
    }
}
