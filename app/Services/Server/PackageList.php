<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Server;

final class PackageList
{
    /**
     * @param  list<string>  $packages
     */
    public static function escape(array $packages): string
    {
        return implode(' ', array_map(escapeshellarg(...), $packages));
    }
}
