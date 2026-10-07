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
                'autoconf',
                'bison',
                'build-essential',
                'gettext',
                'libcurl4-openssl-dev',
                'libedit-dev',
                'libfreetype6-dev',
                'libgd-dev',
                'libicu-dev',
                'libjpeg-dev',
                'libonig-dev',
                'libpng-dev',
                'libpq-dev',
                'libreadline-dev',
                'libsqlite3-dev',
                'libssl-dev',
                'libxml2-dev',
                'libzip-dev',
                'pkg-config',
                're2c',
                'zlib1g-dev',
            ],
            'dnf' => [
                'autoconf',
                'bison',
                'gcc',
                'gcc-c++',
                'make',
                'gettext',
                'libcurl-devel',
                'libedit-devel',
                'freetype-devel',
                'gd-devel',
                'libicu-devel',
                'libjpeg-turbo-devel',
                'oniguruma-devel',
                'libpng-devel',
                'libpq-devel',
                'readline-devel',
                'sqlite-devel',
                'openssl-devel',
                'libxml2-devel',
                'libzip-devel',
                'pkgconf-pkg-config',
                're2c',
                'zlib-devel',
            ],
        ],
        'nodejs' => [
            'apt' => ['python3', 'g++', 'make'],
            'dnf' => ['python3', 'gcc-c++', 'make'],
        ],
        'ruby' => [
            'apt' => ['libssl-dev', 'libreadline-dev', 'zlib1g-dev', 'libyaml-dev', 'libffi-dev', 'libgmp-dev', 'patch'],
            'dnf' => ['openssl-devel', 'readline-devel', 'zlib-devel', 'libyaml-devel', 'libffi-devel', 'gmp-devel', 'patch'],
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
