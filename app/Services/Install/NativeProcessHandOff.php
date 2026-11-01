<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Install;

final class NativeProcessHandOff implements ProcessHandOff
{
    public function runningAsRoot(): bool
    {
        return posix_geteuid() === 0;
    }

    public function replaceWith(string $program, array $arguments): string
    {
        if (!function_exists('pcntl_exec')) {
            return 'this PHP has no pcntl extension';
        }

        @pcntl_exec($program, $arguments);

        return lcfirst(pcntl_strerror(pcntl_get_last_error()));
    }
}
