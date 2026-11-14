<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Runtime;

use App\Models\Server;

final class NativeGoTools extends NativeTools
{
    public const COMPILER = 'C code the Go build compiles';

    private const COMPILERS = ['apt' => ['build-essential'], 'dnf' => ['gcc', 'gcc-c++', 'make']];

    private const PACKAGES = [
        'github.com/mattn/go-sqlite3' => ['apt' => ['gcc'], 'dnf' => ['gcc']],
        'github.com/confluentinc/confluent-kafka-go' => ['apt' => ['gcc'], 'dnf' => ['gcc']],
        'github.com/davidbyttow/govips' => ['apt' => ['pkg-config', 'libvips-dev'], 'dnf' => ['pkgconf-pkg-config', 'vips-devel']],
        'github.com/h2non/bimg' => ['apt' => ['pkg-config', 'libvips-dev'], 'dnf' => ['pkgconf-pkg-config', 'vips-devel']],
        'gopkg.in/gographics/imagick' => ['apt' => ['pkg-config', 'libmagickwand-dev'], 'dnf' => ['pkgconf-pkg-config', 'ImageMagick-devel']],
        'cgo' => ['apt' => ['gcc'], 'dnf' => ['gcc']],
    ];

    public function find(string $appDirectory): string
    {
        $modules = implode('|', array_map(fn(string $module): string => preg_quote($module, '/'), array_keys(array_diff_key(self::PACKAGES, ['cgo' => true]))));
        $sources = implode(' ', array_map(fn(string $glob): string => escapeshellarg($appDirectory) . '/' . $glob, ['*.go', '*/*.go', '*/*/*.go']));

        return '{ grep -ohE ' . escapeshellarg($modules) . ' ' . escapeshellarg($appDirectory . '/go.mod') . ' 2>/dev/null; '
            . 'grep -lsE ' . escapeshellarg('^import "C"$|^[[:space:]]*"C"$') . " {$sources} 2>/dev/null | head -n 1 | sed 's/.*/cgo/'; } | sort -u";
    }

    public function needed(Server $server, string $found): array
    {
        $family = BuildDependencies::family($server->os_name);

        return $family === null || trim($found) === '' ? [] : [self::COMPILER => self::COMPILERS[$family], ...parent::needed($server, $found)];
    }

    protected function packages(): array
    {
        return self::PACKAGES;
    }
}
