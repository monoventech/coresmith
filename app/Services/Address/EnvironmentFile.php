<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Address;

use InvalidArgumentException;
use RuntimeException;

final class EnvironmentFile
{
    private const KEY = '/^[A-Z][A-Z0-9_]*$/';

    private const VALUE = '~^[A-Za-z0-9._:/\[\]-]*$~';

    public function __construct(private readonly string $path) {}

    /**
     * @param  array<string, string>  $values
     */
    public function set(array $values): void
    {
        foreach ($values as $key => $value) {
            if (preg_match(self::KEY, $key) !== 1 || preg_match(self::VALUE, $value) !== 1) {
                throw new InvalidArgumentException("[{$key}] can't be written to the environment file as given.");
            }
        }

        $target = realpath($this->path) ?: $this->path;
        $contents = (string) file_get_contents($target);

        foreach ($values as $key => $value) {
            $pattern = '/^' . $key . '=.*$/m';
            $contents = preg_match($pattern, $contents) === 1
                ? (string) preg_replace($pattern, "{$key}={$value}", $contents, 1)
                : rtrim($contents, "\n") . "\n{$key}={$value}\n";
        }

        $mode = fileperms($target) & 0777;
        $temporary = dirname($target) . '/.' . basename($target) . '.' . bin2hex(random_bytes(4));

        if (file_put_contents($temporary, $contents) === false || !chmod($temporary, $mode) || !rename($temporary, $target)) {
            @unlink($temporary);

            throw new RuntimeException("Coresmith couldn't update {$target}.");
        }
    }
}
