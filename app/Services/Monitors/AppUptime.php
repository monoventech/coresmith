<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Monitors;

use App\Models\AppCheckDay;
use App\Models\Application;
use Carbon\CarbonImmutable;

final class AppUptime
{
    public const DAYS = 30;

    /**
     * @return array{percent: string, days: list<array{date: string, state: string, title: string}>, label: string, note: string}|null
     */
    public function for(Application $application): ?array
    {
        $today = CarbonImmutable::now()->startOfDay();
        $first = $today->subDays(self::DAYS - 1);
        $rows = AppCheckDay::query()
            ->where('application_id', $application->getKey())
            ->where('day', '>=', $first->toDateString())
            ->get()
            ->keyBy(fn(AppCheckDay $day): string => $day->day->toDateString());

        $checks = (int) $rows->sum('checks');

        if ($checks === 0) {
            return null;
        }

        $days = [];
        $counts = ['full' => 0, 'gaps' => 0, 'down' => 0, 'none' => 0];

        for ($date = $first; $date->lte($today); $date = $date->addDay()) {
            $row = $rows->get($date->toDateString());
            $state = match (true) {
                $row === null || $row->checks === 0 => 'none',
                $row->answered === $row->checks => 'full',
                $row->answered * 2 >= $row->checks => 'gaps',
                default => 'down',
            };
            $counts[$state]++;
            $days[] = [
                'date' => $date->toDateString(),
                'state' => $state,
                'title' => $date->format('M j') . ': ' . match ($state) {
                    'none' => 'not checked',
                    'full' => 'every check answered',
                    default => "{$row->answered} of {$row->checks} checks answered",
                },
            ];
        }

        $answered = (int) $rows->sum('answered');
        $failure = $rows->pluck('last_failure_at')->filter()->max();

        return [
            'percent' => $this->percent($answered, $checks),
            'days' => $days,
            'label' => 'Answering on each of the last ' . self::DAYS . ' days: ' . implode(', ', array_filter([
                $counts['full'] > 0 ? "{$counts['full']} with every check answered" : null,
                $counts['gaps'] > 0 ? "{$counts['gaps']} with some failed checks" : null,
                $counts['down'] > 0 ? "{$counts['down']} with most checks failing" : null,
                $counts['none'] > 0 ? "{$counts['none']} not checked" : null,
            ])),
            'note' => 'Coresmith checks it every ' . AppAnswers::EVERY_MINUTES . ' minutes. ' . ($failure === null
                ? 'No failed checks in 30 days.'
                : 'Last failed check ' . $this->when(CarbonImmutable::instance($failure), $today) . '.'),
        ];
    }

    private function percent(int $answered, int $checks): string
    {
        if ($answered === $checks) {
            return '100%';
        }

        return rtrim(rtrim(number_format(floor($answered / $checks * 10000) / 100, 2, '.', ''), '0'), '.') . '%';
    }

    private function when(CarbonImmutable $moment, CarbonImmutable $today): string
    {
        return match (true) {
            $moment->gte($today) => 'today at ' . $moment->format('H:i'),
            $moment->gte($today->subDay()) => 'yesterday at ' . $moment->format('H:i'),
            default => 'on ' . $moment->format('M j') . ' at ' . $moment->format('H:i'),
        };
    }
}
