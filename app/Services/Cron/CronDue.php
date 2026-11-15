<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Cron;

use Cron\CronExpression;
use DateTimeImmutable;
use DateTimeZone;

final class CronDue
{
    public const GRACE_SECONDS = 120;

    /**
     * @param  array{seen?: int, last?: array{started: int, seconds: int, exit: int}|null, running?: int|null}  $state
     */
    public static function missed(string $schedule, array $state, int $now, string $timezone, int $since = 0): ?int
    {
        $due = self::previous($schedule, $now - self::GRACE_SECONDS, $timezone);

        if ($due <= max((int) ($state['seen'] ?? $now), $since)) {
            return null;
        }

        $started = max((int) ($state['last']['started'] ?? 0), (int) ($state['running'] ?? 0));

        return $started >= $due ? null : $due;
    }

    public static function previous(string $schedule, int $moment, string $timezone): int
    {
        return (new CronExpression($schedule))->getPreviousRunDate(self::at($moment, $timezone), 0, true, $timezone)->getTimestamp();
    }

    public static function next(string $schedule, int $moment, string $timezone): int
    {
        return (new CronExpression($schedule))->getNextRunDate(self::at($moment, $timezone), 0, false, $timezone)->getTimestamp();
    }

    private static function at(int $moment, string $timezone): DateTimeImmutable
    {
        return (new DateTimeImmutable('@' . $moment))->setTimezone(new DateTimeZone($timezone));
    }
}
