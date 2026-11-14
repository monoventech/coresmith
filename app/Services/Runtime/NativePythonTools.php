<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Runtime;

final class NativePythonTools extends NativeTools
{
    private const PACKAGES = [
        'psycopg2' => ['apt' => ['gcc', 'libpq-dev'], 'dnf' => ['gcc', 'libpq-devel']],
        'mysqlclient' => ['apt' => ['gcc', 'pkg-config', 'default-libmysqlclient-dev'], 'dnf' => ['gcc', 'pkgconf-pkg-config', 'mariadb-connector-c-devel']],
    ];

    private const FILES = ['requirements.txt', 'pyproject.toml', 'uv.lock', 'poetry.lock', 'Pipfile', 'Pipfile.lock'];

    public function find(string $appDirectory): string
    {
        $names = implode('|', array_keys(self::PACKAGES));
        $files = implode(' ', array_map(fn(string $file): string => escapeshellarg($appDirectory . '/' . $file), self::FILES));

        return 'grep -ohiE ' . escapeshellarg('(^|["\'])(' . $names . ')([^-a-z0-9._]|$)') . " {$files} 2>/dev/null | grep -oiE " . escapeshellarg($names) . " | tr 'A-Z' 'a-z' | sort -u";
    }

    protected function packages(): array
    {
        return self::PACKAGES;
    }
}
