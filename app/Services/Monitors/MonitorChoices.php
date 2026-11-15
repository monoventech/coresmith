<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Monitors;

use App\Enums\MonitorKind;
use App\Jobs\DeployApplication;
use App\Services\Cron\CronDue;

final class MonitorChoices
{
    public const NAMES = [
        'disk' => 'Disk space',
        'memory' => 'Memory',
        'cpu' => 'Processor',
        'load' => 'Load',
        'apps' => 'Apps answering',
        'cron' => 'Cron jobs',
    ];

    public const ANY_ERROR = 400;

    /**
     * @return list<float>
     */
    public static function levels(MonitorKind $kind): array
    {
        return match ($kind) {
            MonitorKind::Disk => [70.0, 75.0, 80.0, 85.0, 90.0],
            MonitorKind::Memory => [85.0, 90.0, 95.0],
            MonitorKind::Cpu => [70.0, 80.0, 90.0, 95.0],
            MonitorKind::Load => [1.0, 1.5, 2.0],
            MonitorKind::Apps => [(float) self::ANY_ERROR, 500.0],
            MonitorKind::Cron => [],
        };
    }

    /**
     * @return list<int>
     */
    public static function durations(MonitorKind $kind): array
    {
        return match ($kind) {
            MonitorKind::Disk => [0, 5, 15],
            MonitorKind::Memory => [2, 4, 10],
            MonitorKind::Cpu => [5, 10, 30],
            MonitorKind::Load => [5, 15, 30],
            MonitorKind::Apps => [2, 3],
            MonitorKind::Cron => [],
        };
    }

    public static function levelLabel(MonitorKind $kind, float $level): string
    {
        return match ($kind) {
            MonitorKind::Load => self::number($level) . ' per core',
            MonitorKind::Apps => $level <= self::ANY_ERROR ? 'Any error' : 'Server errors',
            default => self::number($level) . '%',
        };
    }

    public static function durationLabel(MonitorKind $kind, int $duration): string
    {
        return match (true) {
            $kind === MonitorKind::Apps => ucfirst(self::checks($duration)) . ' checks in a row',
            $duration === 0 => 'As soon as seen',
            default => "For {$duration} minutes",
        };
    }

    public static function rule(MonitorKind $kind, float $threshold, int $duration): string
    {
        $level = self::number($threshold);
        $for = $duration === 0 ? 'as soon as a reading shows it' : "for {$duration} minutes";

        return match ($kind) {
            MonitorKind::Disk => "Above {$level}% used" . ($duration === 0 ? ", {$for}" : " {$for}") . ', and again at ' . DeployApplication::DISK_ABORT_PERCENT . '%, where deploys stop',
            MonitorKind::Memory => "Above {$level}% used {$for}",
            MonitorKind::Cpu => "Above {$level}% busy {$for}",
            MonitorKind::Load => "Above {$level} per core {$for}",
            MonitorKind::Apps => "An app's domain doesn't answer, or answers with " . ($threshold <= self::ANY_ERROR ? 'any error' : 'a server error') . ', ' . self::checks($duration) . ' checks in a row',
            MonitorKind::Cron => "A job exits with an error, or doesn't run within " . intdiv(CronDue::GRACE_SECONDS, 60) . " minutes of when it's due",
        };
    }

    public static function serverRule(MonitorKind $kind, float $threshold, int $duration): string
    {
        return self::NAMES[$kind->value] . ' ' . lcfirst(self::rule($kind, $threshold, $duration));
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    private static function checks(int $duration): string
    {
        return match ($duration) {
            2 => 'two',
            3 => 'three',
            default => (string) $duration,
        };
    }
}
