<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Caddy;

final class SensitivePaths
{
    public const PATHS = [
        '/.env*',
        '/.git/*',
        '/.svn/*',
        '/.hg/*',
        '/storage/*',
        '/vendor/*',
        '/node_modules/*',
        '/.htaccess',
        '/web.config',
        '/composer.json',
        '/composer.lock',
        '/package.json',
        '/bun.lockb',
        '/artisan',
        '/webpack.mix.js',
        '/vite.config.*',
        '/.editorconfig',
        '/.tool-versions',
        '/docker-compose*',
        '/Dockerfile*',
    ];
}
