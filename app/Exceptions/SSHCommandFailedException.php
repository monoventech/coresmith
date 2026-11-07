<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class SSHCommandFailedException extends RuntimeException
{
    public function __construct(
        private readonly string $commandOutput,
        private readonly int $commandExitCode,
    ) {
        parent::__construct("SSH command failed with exit code {$commandExitCode}.");
    }

    public function output(): string
    {
        return $this->commandOutput;
    }

    public function exitCode(): int
    {
        return $this->commandExitCode;
    }
}
