<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Install;

interface ProcessHandOff
{
    public function runningAsRoot(): bool;

    /**
     * @param  list<string>  $arguments
     */
    public function replaceWith(string $program, array $arguments): string;
}
