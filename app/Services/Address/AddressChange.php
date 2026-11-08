<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Address;

use App\Models\InstanceSetting;

final class AddressChange
{
    public const SETTING = 'address_change';

    public static function begin(string $from, string $to): void
    {
        self::store(['state' => 'running', 'from' => $from, 'to' => $to, 'error' => null, 'started_at' => now()->toIso8601String(), 'finished_at' => null]);
    }

    public static function fail(string $error): void
    {
        self::store([...(self::current() ?? []), 'state' => 'failed', 'error' => $error, 'finished_at' => now()->toIso8601String()]);
    }

    public static function finish(): void
    {
        self::store([...(self::current() ?? []), 'state' => 'done', 'error' => null, 'finished_at' => now()->toIso8601String()]);
    }

    public static function running(): bool
    {
        return (self::current()['state'] ?? null) === 'running';
    }

    /**
     * @return array{state: string, from: string, to: string, error: ?string, started_at: string, finished_at: ?string}|null
     */
    public static function current(): ?array
    {
        $value = json_decode((string) InstanceSetting::get(self::SETTING), true);

        return is_array($value) ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $change
     */
    private static function store(array $change): void
    {
        InstanceSetting::put(self::SETTING, (string) json_encode($change));
    }
}
