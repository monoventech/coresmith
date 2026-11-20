<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Panel;

use App\Models\BackupDestination;
use App\Models\InstanceSetting;
use App\Services\Address\InstallationAddress;

final class BackupCopy
{
    public const SETTING = 'backup_copy';

    public static function key(BackupDestination $destination, string $archive): string
    {
        $host = (string) preg_replace('/[^A-Za-z0-9._-]/', '-', app(InstallationAddress::class)->host());

        return ltrim(trim((string) $destination->prefix, '/') . '/coresmith/' . $host . '/' . basename($archive), '/');
    }

    public static function record(bool $ok, string $key, ?string $error = null): void
    {
        InstanceSetting::put(self::SETTING, (string) json_encode(['ok' => $ok, 'key' => $key, 'error' => $error, 'at' => now()->toIso8601String()]));
    }

    /**
     * @return array{ok: bool, key: string, error: ?string, at: string}|null
     */
    public static function status(): ?array
    {
        $value = json_decode((string) InstanceSetting::get(self::SETTING), true);

        return is_array($value) && is_bool($value['ok'] ?? null) && is_string($value['at'] ?? null) ? $value : null;
    }

    public static function forget(): void
    {
        InstanceSetting::put(self::SETTING, null);
    }
}
