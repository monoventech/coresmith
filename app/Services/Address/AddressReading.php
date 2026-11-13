<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Address;

use App\Models\InstanceSetting;

final class AddressReading
{
    public const SETTING = 'address_reach';

    public static function record(string $host, ReachReport $report): void
    {
        InstanceSetting::put(self::SETTING, (string) json_encode([
            'host' => $host,
            'ready' => $report->ready,
            'problems' => $report->problems,
            'notes' => $report->notes,
            'records' => $report->records,
            'checked_at' => now()->toIso8601String(),
        ]));
    }

    /**
     * @return array{host: string, ready: ?bool, problems: list<string>, notes: list<string>, records: list<array{type: string, name: string, value: string}>, checked_at: string}|null
     */
    public static function current(string $host): ?array
    {
        $value = json_decode((string) InstanceSetting::get(self::SETTING), true);

        return is_array($value) && ($value['host'] ?? null) === $host ? $value : null;
    }
}
