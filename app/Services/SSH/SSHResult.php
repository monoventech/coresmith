<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\SSH;

use App\Exceptions\SSHCommandFailedException;

final readonly class SSHResult
{
    public function __construct(
        public string $output,
        public int $exitCode,
    ) {}

    public function successful(): bool
    {
        return $this->exitCode === 0;
    }

    public function failed(): bool
    {
        return !$this->successful();
    }

    public function throw(): static
    {
        if ($this->failed()) {
            throw new SSHCommandFailedException($this->output, $this->exitCode);
        }

        return $this;
    }
}
