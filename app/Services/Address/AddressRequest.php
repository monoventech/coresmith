<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Address;

final class AddressRequest
{
    public static function file(): string
    {
        return rtrim((string) config('coresmith.address.state_path'), '/') . '/request.json';
    }

    public static function takenFile(): string
    {
        return rtrim((string) config('coresmith.address.state_path'), '/') . '/request.taken.json';
    }

    public static function submit(string $host): void
    {
        AtomicFile::write(self::file(), (string) json_encode(['host' => $host, 'requested_at' => now()->toIso8601String()]), 0640, "Coresmith couldn't ask for the change");
    }

    public static function withdraw(): bool
    {
        return is_file(self::file()) && @unlink(self::file());
    }

    public static function take(): ?string
    {
        foreach ([self::takenFile(), self::file()] as $file) {
            if (!is_file($file)) {
                continue;
            }

            $request = json_decode((string) @file_get_contents($file), true);
            @unlink($file);
            $host = is_array($request) ? ($request['host'] ?? null) : null;

            return is_string($host) && $host !== '' ? $host : null;
        }

        return null;
    }
}
