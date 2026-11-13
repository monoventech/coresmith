<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Deployment;

final class DatabaseFailure
{
    private const REFUSED = [
        'MySQL' => ['SQLSTATE[HY000] [2002]', 'SQLSTATE[HY000] [2006]'],
        'PostgreSQL' => ['SQLSTATE[08006]', 'SQLSTATE[08001]'],
    ];

    public static function reason(string $output): ?string
    {
        foreach (self::REFUSED as $engine => $markers) {
            foreach ($markers as $marker) {
                if (str_contains($output, $marker)) {
                    return "Migrations couldn't connect to {$engine} where the app's settings point, so nothing was changed. Add a {$engine} database for the app on its server, or check its DB_ settings. To use SQLite instead, set DB_CONNECTION=sqlite.";
                }
            }
        }

        return null;
    }
}
