<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Deployment;

final class CronExpression
{
    public const MAX_LENGTH = 100;

    /** @var list<array{0: string, 1: string, 2: string, 3: int, 4: int}> */
    public const FIELDS = [
        ['minute', 'a minute', 'Minutes', 0, 59],
        ['hour', 'an hour', 'Hours', 0, 23],
        ['day of the month', 'a day of the month', 'Days of the month', 1, 31],
        ['month', 'a month', 'Months', 1, 12],
        ['day of the week', 'a day of the week', 'Days of the week', 0, 7],
    ];

    private const ITEM = '/^(\*|(\d+)(?:-(\d+))?)(?:\/(\d+))?\z/';

    public static function problem(string $schedule): ?string
    {
        if (strlen($schedule) > self::MAX_LENGTH) {
            return 'That schedule is too long. Cron schedules are five short fields, like */5 * * * *.';
        }

        if (preg_match(CrontabBuilder::SCHEDULE_PATTERN, $schedule) !== 1) {
            return 'Write five fields separated by single spaces, using only digits and * / , - in each, such as */5 * * * *.';
        }

        foreach (explode(' ', $schedule) as $index => $field) {
            foreach (explode(',', $field) as $item) {
                $problem = self::itemProblem($item, self::FIELDS[$index]);

                if ($problem !== null) {
                    return $problem;
                }
            }
        }

        return null;
    }

    /**
     * @param  array{0: string, 1: string, 2: string, 3: int, 4: int}  $field
     */
    private static function itemProblem(string $item, array $field): ?string
    {
        [$name, $noun, $plural, $min, $max] = $field;

        if ($item === '') {
            return "The {$name} field has an empty item. Remove the extra comma.";
        }

        if (preg_match(self::ITEM, $item, $matches) !== 1) {
            return "{$item} isn't something cron can read in the {$name} field. Use a number, a range like 1-5, a list like 1,15 or a step like */10.";
        }

        $start = $matches[2] ?? '';
        $end = $matches[3] ?? '';
        $step = $matches[4] ?? '';

        foreach ([$start, $end] as $number) {
            if ($number !== '' && ((int) $number < $min || (int) $number > $max)) {
                return "{$number} isn't {$noun}. {$plural} run from {$min} to {$max}." . ($name === 'day of the week' ? ' 0 and 7 are both Sunday.' : '');
            }
        }

        if ($start !== '' && $end !== '' && (int) $start > (int) $end) {
            return "{$start}-{$end} runs backwards, so it never matches. Put the smaller number first.";
        }

        if ($step === '') {
            return null;
        }

        if ((int) $step === 0) {
            return 'A step of 0 never moves on. Use 1 or more, like */5.';
        }

        if ($start !== '' && $end === '') {
            return "{$start}/{$step} has a step but no range. Write */{$step} or {$start}-{$max}/{$step} instead.";
        }

        if ((int) $step > $max) {
            return "/{$step} is too big a step for " . strtolower($plural) . ". Use up to {$max}.";
        }

        return null;
    }
}
