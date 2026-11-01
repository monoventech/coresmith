<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Updates;

final class UpdatePaths
{
    public function __construct(
        private readonly string $root,
        private readonly string $state,
        private readonly string $keys,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            self::absolute((string) config('coresmith.updates.root')),
            self::absolute((string) config('coresmith.updates.state_path')),
            self::absolute((string) config('coresmith.updates.keys_path')),
        );
    }

    public function root(): string
    {
        return $this->root;
    }

    public function releases(): string
    {
        return "{$this->root}/releases";
    }

    public function shared(): string
    {
        return "{$this->root}/shared";
    }

    public function current(): string
    {
        return "{$this->root}/app";
    }

    public function state(): string
    {
        return $this->state;
    }

    public function keys(): string
    {
        return $this->keys;
    }

    public function checkFile(): string
    {
        return "{$this->state}/check.json";
    }

    public function requestFile(): string
    {
        return "{$this->state}/request.json";
    }

    public function takenRequestFile(): string
    {
        return "{$this->state}/request.taken.json";
    }

    public function statusFile(): string
    {
        return "{$this->state}/status.json";
    }

    public function lockFile(): string
    {
        return "{$this->state}/update.lock";
    }

    public function runs(): string
    {
        return "{$this->state}/runs";
    }

    private static function absolute(string $path): string
    {
        $path = str_starts_with($path, '/') ? $path : base_path($path);

        return rtrim($path, '/') ?: '/';
    }
}
