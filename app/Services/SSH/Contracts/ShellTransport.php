<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\SSH\Contracts;

interface ShellTransport
{
    public function openShell(int $columns, int $rows, string $command): void;

    public function readShell(float $seconds): ?string;

    public function writeShell(string $bytes): void;

    public function resizeShell(int $columns, int $rows): void;
}
