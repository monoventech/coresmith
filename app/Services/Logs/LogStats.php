<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Logs;

final class LogStats
{
    public const SLOWEST = 3;

    /**
     * @param  list<array{at: float|null, method: string, path: string, status: int, ms: int}>  $requests
     * @return array{requests: int, per_minute: float|null, server_errors: int, error_rate: float|null, p50: int|null, p95: int|null, slowest: list<array{path: string, ms: int, count: int}>}
     */
    public static function of(array $requests, ?int $seconds): array
    {
        $count = count($requests);
        $durations = array_column($requests, 'ms');
        sort($durations);
        $times = array_filter(array_column($requests, 'at'), fn(mixed $at): bool => $at !== null);
        $span = $seconds ?? ($times === [] ? null : (int) ceil(max($times) - min($times)));
        $serverErrors = count(array_filter($requests, fn(array $request): bool => $request['status'] >= 500));
        $paths = [];

        foreach ($requests as $request) {
            $paths[$request['method'] . ' ' . strtok($request['path'], '?')][] = $request['ms'];
        }

        $slowest = [];

        foreach ($paths as $path => $milliseconds) {
            sort($milliseconds);
            $slowest[] = ['path' => (string) $path, 'ms' => (int) self::percentile($milliseconds, 0.5), 'count' => count($milliseconds)];
        }

        usort($slowest, fn(array $a, array $b): int => [$b['ms'], $b['count']] <=> [$a['ms'], $a['count']]);

        return [
            'requests' => $count,
            'per_minute' => $count === 0 || $span === null || $span <= 0 ? null : round($count / max(1, $span / 60), 1),
            'server_errors' => $serverErrors,
            'error_rate' => $count === 0 ? null : round($serverErrors / $count * 100, 1),
            'p50' => self::percentile($durations, 0.5),
            'p95' => self::percentile($durations, 0.95),
            'slowest' => array_slice($slowest, 0, self::SLOWEST),
        ];
    }

    /**
     * @param  list<int>  $sorted
     */
    private static function percentile(array $sorted, float $fraction): ?int
    {
        if ($sorted === []) {
            return null;
        }

        return $sorted[max(0, (int) ceil($fraction * count($sorted)) - 1)];
    }
}
