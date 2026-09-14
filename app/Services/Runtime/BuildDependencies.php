<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Runtime;

final class BuildDependencies
{
    private const DEPENDENCIES = [
        'php' => [
            'apt' => [
                'libxml2-dev',
                'libssl-dev',
                'libcurl4-openssl-dev',
                'libpng-dev',
                'libjpeg-dev',
                'libfreetype6-dev',
                'libzip-dev',
                'libonig-dev',
                'libreadline-dev',
                'libsqlite3-dev',
                'libpq-dev',
            ],
            'dnf' => [
                'libxml2-devel',
                'openssl-devel',
                'curl-devel',
                'libpng-devel',
                'libjpeg-devel',
                'freetype-devel',
                'libzip-devel',
                'oniguruma-devel',
                'readline-devel',
                'sqlite-devel',
                'libpq-devel',
            ],
        ],
        'nodejs' => [
            'apt' => ['python3', 'g++', 'make'],
            'dnf' => ['python3', 'gcc-c++', 'make'],
        ],
        'ruby' => [
            'apt' => ['libssl-dev', 'libreadline-dev', 'zlib1g-dev', 'libyaml-dev', 'libffi-dev'],
            'dnf' => ['openssl-devel', 'readline-devel', 'zlib-devel', 'libyaml-devel', 'libffi-devel'],
        ],
    ];

    private const FAMILIES = [
        'ubuntu' => 'apt',
        'debian' => 'apt',
        'rocky' => 'dnf',
        'almalinux' => 'dnf',
        'fedora' => 'dnf',
        'amzn' => 'dnf',
    ];

    /**
     * @return list<string>
     */
    public static function for(string $runtime, ?string $osName): array
    {
        $family = self::FAMILIES[$osName] ?? null;

        if ($family === null) {
            return [];
        }

        return self::DEPENDENCIES[$runtime][$family] ?? [];
    }
}
