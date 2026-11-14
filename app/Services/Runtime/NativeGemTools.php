<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Runtime;

final class NativeGemTools extends NativeTools
{
    private const GEMS = [
        'rb_sys' => ['apt' => ['libclang-dev'], 'dnf' => ['clang-devel']],
        'rugged' => ['apt' => ['cmake'], 'dnf' => ['cmake']],
    ];

    public function find(string $appDirectory): string
    {
        return 'grep -ohE ' . escapeshellarg('^    (' . implode('|', array_keys(self::GEMS)) . ') \(') . ' ' . escapeshellarg($appDirectory . '/Gemfile.lock') . " 2>/dev/null | awk '{print \$1}' | sort -u";
    }

    protected function packages(): array
    {
        return self::GEMS;
    }
}
