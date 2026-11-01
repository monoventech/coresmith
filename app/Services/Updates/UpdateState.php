<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Updates;

use RuntimeException;

final class UpdateState
{
    public function __construct(private readonly UpdatePaths $paths) {}

    public function readCheck(): ?UpdateCheck
    {
        $data = $this->read($this->paths->checkFile());

        return $data === null ? null : UpdateCheck::fromArray($data);
    }

    public function writeCheck(UpdateCheck $check): void
    {
        $this->write($this->paths->checkFile(), $check->toArray());
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private function read(string $file): ?array
    {
        if (!is_file($file)) {
            return null;
        }

        $data = json_decode((string) @file_get_contents($file), true);

        return is_array($data) ? $data : null;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private function write(string $file, array $data): void
    {
        $directory = dirname($file);

        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException("Coresmith couldn't create {$directory}.");
        }

        @chmod($directory, 0700);

        $temporary = @tempnam($directory, '.' . basename($file) . '.');

        if ($temporary === false) {
            throw new RuntimeException("Coresmith couldn't write to {$directory}.");
        }

        if (@file_put_contents($temporary, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n") === false
            || !@chmod($temporary, 0600)
            || !@rename($temporary, $file)) {
            @unlink($temporary);

            throw new RuntimeException("Coresmith couldn't write {$file}.");
        }
    }
}
