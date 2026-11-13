<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Database;

use App\Models\Application;
use App\Models\ManagedDatabase;

final class DatabaseUsage
{
    public function mentions(Application $application, ManagedDatabase $database): bool
    {
        $name = (string) $database->name;

        foreach (preg_split('/\r\n|\n|\r/', (string) $application->env_variables) ?: [] as $line) {
            if (preg_match('/^\s*(?:export\s+)?[A-Za-z_][A-Za-z0-9_]*\s*=\s*(.*)$/', $line, $match) !== 1) {
                continue;
            }

            $value = trim(trim($match[1]), "\"'");
            $path = parse_url($value, PHP_URL_PATH);

            if ($value === $name || (str_contains($value, '://') && is_string($path) && trim($path, '/') === $name)) {
                return true;
            }
        }

        return false;
    }
}
