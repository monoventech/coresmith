<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Server;

use App\Models\Server;

final class BasePackages
{
    private const COMMON = [
        'curl', 'wget', 'git', 'unzip', 'zip', 'jq', 'htop', 'ncdu',
        'gcc', 'gcc-c++', 'make', 'autoconf', 'automake', 'openssl',
    ];

    private const DEBIAN = [
        'curl', 'wget', 'git', 'unzip', 'zip', 'jq', 'htop', 'ncdu',
        'build-essential', 'autoconf', 'automake',
        'openssl', 'libssl-dev',
        'libmysqlclient-dev', 'libpq-dev',
        'libxml2-dev', 'libzip-dev', 'libpng-dev', 'libjpeg-dev',
        'sqlite3', 'libsqlite3-dev',
        'redis-tools',
    ];

    private const RHEL = [
        'curl', 'wget', 'git', 'unzip', 'zip', 'jq', 'htop', 'ncdu',
        'gcc', 'gcc-c++', 'make', 'autoconf', 'automake',
        'openssl', 'openssl-devel',
        'mysql-devel', 'libpq-devel',
        'libxml2-devel', 'libzip-devel', 'libpng-devel', 'libjpeg-turbo-devel',
        'sqlite', 'sqlite-devel',
        'redis',
    ];

    /**
     * @return list<string>
     */
    public static function for(Server $server): array
    {
        return match ($server->os_name) {
            'ubuntu', 'debian' => self::DEBIAN,
            'rocky', 'almalinux', 'fedora', 'amzn' => self::RHEL,
            default => self::COMMON,
        };
    }
}
