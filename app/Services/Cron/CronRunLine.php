<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Cron;

use App\Models\MonitorAlert;
use Carbon\CarbonImmutable;

final class CronRunLine
{
    /**
     * @param  array{schedule: string}  $entry
     * @param  array{seen?: int, last?: array{started: int, seconds: int, exit: int}|null, running?: int|null}  $state
     * @return array{state: 'succeeded'|'failed'|'missed'|'running'|'none', text: string}
     */
    public static function for(array $entry, array $state, int $now, string $timezone, ?MonitorAlert $alert, bool $scheduled): array
    {
        $last = is_array($state['last'] ?? null) ? $state['last'] : null;
        $running = isset($state['running']) ? (int) $state['running'] : null;
        $missed = $alert?->level === 'missed' ? (int) $alert->peak : null;

        [$kind, $text] = match (true) {
            $running !== null => ['running', 'Running since ' . self::since($running, $now, $timezone)],
            $missed !== null => ['missed', "Didn't run when due " . self::when($missed, $now, $timezone)],
            $last !== null && (int) $last['exit'] !== 0 => ['failed', 'Failed ' . self::when((int) $last['started'], $now, $timezone) . ' after ' . self::took((int) $last['seconds']) . ', exit code ' . (int) $last['exit']],
            $last !== null => ['succeeded', 'Ran ' . self::when((int) $last['started'], $now, $timezone) . ' in ' . self::took((int) $last['seconds'])],
            default => ['none', "Hasn't run yet"],
        };

        if ($scheduled) {
            $text .= ' · ' . ($kind === 'none' ? 'first ' : 'next ') . self::when(CronDue::next($entry['schedule'], $now, $timezone), $now, $timezone);
        }

        return ['state' => $kind, 'text' => $text];
    }

    public static function when(int $moment, int $now, string $timezone): string
    {
        $day = self::day($moment, $now, $timezone);
        $time = CarbonImmutable::createFromTimestamp($moment, $timezone)->format('g:i A');

        return $day === null ? "at {$time}" : "{$day} at {$time}";
    }

    public static function took(int $seconds): string
    {
        return match (true) {
            $seconds < 1 => 'under a second',
            $seconds === 1 => '1 second',
            $seconds < 120 => "{$seconds} seconds",
            $seconds < 7200 => intdiv($seconds, 60) . ' minutes',
            default => intdiv($seconds, 3600) . ' hours',
        };
    }

    private static function since(int $moment, int $now, string $timezone): string
    {
        $day = self::day($moment, $now, $timezone);
        $time = CarbonImmutable::createFromTimestamp($moment, $timezone)->format('g:i A');

        return $day === null ? $time : "{$day} at {$time}";
    }

    private static function day(int $moment, int $now, string $timezone): ?string
    {
        $at = CarbonImmutable::createFromTimestamp($moment, $timezone);
        $today = CarbonImmutable::createFromTimestamp($now, $timezone)->startOfDay();
        $days = (int) $today->diffInDays($at->startOfDay(), false);

        return match (true) {
            $days === 0 => null,
            $days === 1 => 'tomorrow',
            $days === -1 => 'yesterday',
            $days > 1 && $days <= 7 => $at->format('l'),
            default => $at->format('M j'),
        };
    }
}
