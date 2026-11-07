<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Panel;

use App\Services\Deployment\CronExpression;

final class CronSchedule
{
    public const KINDS = ['minute', 'hourly', 'daily', 'weekly', 'custom'];

    private const WEEKDAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

    private const DEFAULTS = ['minute' => 0, 'hour' => 3, 'weekday' => 1];

    public static function sentence(string $schedule): ?string
    {
        if (CronExpression::problem($schedule) !== null) {
            return null;
        }

        [$minute, $hour, $day, $month, $weekday] = explode(' ', $schedule);

        if ($month !== '*') {
            return null;
        }

        if ($day === '*' && $weekday === '*') {
            return self::number($minute) && self::number($hour)
                ? 'Daily at ' . self::time((int) $hour, (int) $minute)
                : self::frequent($minute, $hour);
        }

        if (!self::number($minute) || !self::number($hour)) {
            return null;
        }

        $time = self::time((int) $hour, (int) $minute);

        return match (true) {
            $day === '*' && self::number($weekday) => 'Weekly on ' . self::WEEKDAYS[(int) $weekday] . " at {$time}",
            $day === '*' && $weekday === '1-5' => "Weekdays at {$time}",
            $weekday === '*' && self::number($day) => 'Monthly on the ' . self::ordinal((int) $day) . " at {$time}",
            default => null,
        };
    }

    public static function phrase(string $schedule): string
    {
        $sentence = self::sentence($schedule);

        return $sentence === null ? 'on a custom schedule' : lcfirst($sentence);
    }

    /**
     * @return array{kind: string, minute: int, hour: int, weekday: int}
     */
    public static function preset(string $schedule): array
    {
        if (CronExpression::problem($schedule) !== null) {
            return ['kind' => 'custom', ...self::DEFAULTS];
        }

        [$minute, $hour, $day, $month, $weekday] = explode(' ', $schedule);

        if ($schedule === '* * * * *') {
            return ['kind' => 'minute', ...self::DEFAULTS];
        }

        if ($day !== '*' || $month !== '*' || !self::number($minute)) {
            return ['kind' => 'custom', ...self::DEFAULTS];
        }

        return match (true) {
            $hour === '*' && $weekday === '*' => ['kind' => 'hourly', ...self::DEFAULTS, 'minute' => (int) $minute],
            self::number($hour) && $weekday === '*' => ['kind' => 'daily', ...self::DEFAULTS, 'minute' => (int) $minute, 'hour' => (int) $hour],
            self::number($hour) && self::number($weekday) => ['kind' => 'weekly', 'minute' => (int) $minute, 'hour' => (int) $hour, 'weekday' => (int) $weekday % 7],
            default => ['kind' => 'custom', ...self::DEFAULTS],
        };
    }

    private static function frequent(string $minute, string $hour): ?string
    {
        if ($hour === '*') {
            return match (true) {
                $minute === '*' || $minute === '*/1' => 'Every minute',
                str_starts_with($minute, '*/') && self::divides(substr($minute, 2), 60) => 'Every ' . (int) substr($minute, 2) . ' minutes',
                self::number($minute) => (int) $minute === 0 ? 'Every hour' : 'Every hour at :' . self::padded((int) $minute),
                default => null,
            };
        }

        if (!self::number($minute) || !str_starts_with($hour, '*/') || !self::divides(substr($hour, 2), 24)) {
            return null;
        }

        $hours = (int) substr($hour, 2);
        $past = (int) $minute === 0 ? '' : ' at :' . self::padded((int) $minute);

        return $hours === 1 ? "Every hour{$past}" : "Every {$hours} hours{$past}";
    }

    private static function time(int $hour, int $minute): string
    {
        $twelve = $hour % 12 === 0 ? 12 : $hour % 12;

        return $twelve . ':' . self::padded($minute) . ($hour < 12 ? ' AM' : ' PM') . ' UTC';
    }

    private static function ordinal(int $day): string
    {
        $suffix = match (true) {
            $day % 100 >= 11 && $day % 100 <= 13 => 'th',
            $day % 10 === 1 => 'st',
            $day % 10 === 2 => 'nd',
            $day % 10 === 3 => 'rd',
            default => 'th',
        };

        return $day . $suffix;
    }

    private static function padded(int $value): string
    {
        return str_pad((string) $value, 2, '0', STR_PAD_LEFT);
    }

    private static function number(string $field): bool
    {
        return $field !== '' && ctype_digit($field);
    }

    private static function divides(string $step, int $whole): bool
    {
        return self::number($step) && (int) $step > 0 && $whole % (int) $step === 0;
    }
}
