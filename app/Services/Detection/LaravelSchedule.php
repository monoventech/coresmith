<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Detection;

use App\Services\Runtime\RepositoryFacts;

final class LaravelSchedule
{
    public const FILES = ['routes/console.php', 'app/Console/Kernel.php', 'bootstrap/app.php'];

    private const SCHEDULED = '/\bSchedule::(?:command|call|job|exec)\s*\(|\$schedule->(?:command|call|job|exec)\s*\(/';

    public static function detected(?RepositoryFacts $facts): bool
    {
        if ($facts === null) {
            return false;
        }

        $code = implode("\n", array_map(fn(string $file): string => self::withoutComments((string) $facts->file($file)), self::FILES));

        return preg_match(self::SCHEDULED, $code) === 1;
    }

    private static function withoutComments(string $php): string
    {
        return (string) preg_replace('~/\*.*?\*/|//[^\n]*|^\s*#(?!\[)[^\n]*~ms', '', $php);
    }
}
