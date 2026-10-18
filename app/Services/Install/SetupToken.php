<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Install;

use Illuminate\Support\Str;

final class SetupToken
{
    public const LENGTH = 64;

    public function path(): string
    {
        $configured = (string) config('coresmith.setup_token_path');

        return str_starts_with($configured, '/') ? $configured : base_path($configured);
    }

    public function exists(): bool
    {
        return is_file($this->path());
    }

    public function generate(): string
    {
        $token = Str::random(self::LENGTH);
        $directory = dirname($this->path());

        if (!is_dir($directory)) {
            mkdir($directory, 0700, true);
        }

        file_put_contents($this->path(), $token . PHP_EOL);
        chmod($this->path(), 0600);

        return $token;
    }

    public function matches(?string $candidate): bool
    {
        if (!$this->exists() || $candidate === null || $candidate === '') {
            return false;
        }

        $expected = trim((string) file_get_contents($this->path()));

        return $expected !== '' && hash_equals($expected, $candidate);
    }

    public function forget(): void
    {
        if (!$this->exists()) {
            return;
        }

        if (!@unlink($this->path())) {
            @file_put_contents($this->path(), '');
        }
    }
}
