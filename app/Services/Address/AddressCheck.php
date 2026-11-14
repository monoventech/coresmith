<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Address;

use App\Models\InstanceSetting;

final class AddressCheck
{
    public const SETTING = 'address_check';

    public static function record(string $host, AddressVerdict $verdict): void
    {
        InstanceSetting::put(self::SETTING, (string) json_encode([
            'host' => $host,
            'ok' => $verdict->refusal === null,
            'problems' => $verdict->problems(),
            'notes' => $verdict->notes,
            'records' => $verdict->records,
            'checked_at' => now()->toIso8601String(),
        ]));
    }

    /**
     * @return array{host: string, ok: bool, problems: list<string>, notes: list<string>, records?: list<array{type: string, name: string, value: string, note: string|null}>, checked_at: string}|null
     */
    public static function current(): ?array
    {
        $value = json_decode((string) InstanceSetting::get(self::SETTING), true);

        return is_array($value) ? $value : null;
    }
}
