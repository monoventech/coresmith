<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Process;

use App\Models\Server;

final class AppSandbox
{
    public const SLICE = 'coresmith-apps.slice';

    private const INACCESSIBLE_PATHS_SINCE = 231;

    private const PROTECT_PROC_SINCE = 247;

    private const HIDDEN = [
        '/opt/coresmith',
        '/var/lib/coresmith',
        '/var/lib/coresmith-this-machine',
        '/etc/caddy',
        '/run/caddy-admin',
        '/var/log/coresmith-install.log',
        '/var/log/coresmith-horizon.log',
        '/var/log/coresmith-reverb.log',
        '/var/log/coresmith-fpm-error.log',
    ];

    /**
     * @return list<string>
     */
    public static function properties(?Server $server): array
    {
        if ($server === null || !$server->runs_coresmith) {
            return [];
        }

        $systemd = $server->systemdVersion() ?? 0;

        return [
            'Slice=' . self::SLICE,
            ...($systemd >= self::INACCESSIBLE_PATHS_SINCE ? ['InaccessiblePaths=' . implode(' ', array_map(fn(string $path): string => '-' . $path, self::HIDDEN))] : []),
            ...($systemd >= self::PROTECT_PROC_SINCE ? ['ProtectProc=invisible'] : []),
        ];
    }
}
