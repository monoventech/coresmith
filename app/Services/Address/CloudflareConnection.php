<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Address;

use App\Models\InstanceSetting;
use App\Services\VaultEncryptor;
use Throwable;

final class CloudflareConnection
{
    public const SETTING = 'cloudflare_dns';

    public static function connect(string $token): void
    {
        self::store(['token' => app(VaultEncryptor::class)->encrypt($token), 'host' => null, 'error' => null, 'synced_at' => null]);
    }

    public static function disconnect(): void
    {
        InstanceSetting::put(self::SETTING, null);
    }

    public static function token(): ?string
    {
        $stored = self::stored()['token'] ?? null;

        if (!is_string($stored) || $stored === '') {
            return null;
        }

        try {
            return app(VaultEncryptor::class)->decrypt($stored);
        } catch (Throwable) {
            return null;
        }
    }

    public static function synced(string $host, ?string $error): void
    {
        $stored = self::stored();

        if ($stored !== null) {
            self::store([...$stored, 'host' => $host, 'error' => $error, 'synced_at' => now()->toIso8601String()]);
        }
    }

    /**
     * @return array{host: ?string, error: ?string, synced_at: ?string}|null
     */
    public static function status(): ?array
    {
        $stored = self::stored();

        return $stored === null ? null : ['host' => $stored['host'] ?? null, 'error' => $stored['error'] ?? null, 'synced_at' => $stored['synced_at'] ?? null];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function stored(): ?array
    {
        $value = json_decode((string) InstanceSetting::get(self::SETTING), true);

        return is_array($value) ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private static function store(array $value): void
    {
        InstanceSetting::put(self::SETTING, (string) json_encode($value));
    }
}
