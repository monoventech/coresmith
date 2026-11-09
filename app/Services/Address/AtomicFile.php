<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Address;

use RuntimeException;

final class AtomicFile
{
    public static function write(string $path, string $contents, int $mode, string $failure): void
    {
        $temporary = dirname($path) . '/.' . basename($path) . '.' . bin2hex(random_bytes(4));

        if (@file_put_contents($temporary, $contents) === false || !@chmod($temporary, $mode) || !@rename($temporary, $path)) {
            $reason = preg_replace('/^\w+\([^)]*\):\s*/', '', (string) (error_get_last()['message'] ?? 'the file system refused it'));
            @unlink($temporary);

            throw new RuntimeException("{$failure}: {$reason}");
        }
    }
}
