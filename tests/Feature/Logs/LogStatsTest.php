<?php

declare(strict_types=1);

use App\Services\Logs\LogStats;

function logStatsRequest(string $path, int $ms, int $status = 200, float $at = 1795521601.0, string $method = 'GET'): array
{
    return ['at' => $at, 'method' => $method, 'path' => $path, 'status' => $status, 'ms' => $ms];
}

test('the requests in a window give the rate, server errors, response times and slowest paths', function () {
    $requests = [
        ...array_map(fn(int $index): array => logStatsRequest('/?page=' . $index, 40), range(1, 16)),
        logStatsRequest('/api/report', 2100),
        logStatsRequest('/api/report', 1900, 500),
        logStatsRequest('/checkout?token=x', 840),
        logStatsRequest('/search', 610),
    ];

    expect(LogStats::of($requests, 600))->toBe([
        'requests' => 20,
        'per_minute' => 2.0,
        'server_errors' => 1,
        'error_rate' => 5.0,
        'p50' => 40,
        'p95' => 1900,
        'slowest' => [
            ['path' => 'GET /api/report', 'ms' => 1900, 'count' => 2],
            ['path' => 'GET /checkout', 'ms' => 840, 'count' => 1],
            ['path' => 'GET /search', 'ms' => 610, 'count' => 1],
        ],
    ]);
});

test('with no requests there is nothing to measure', function () {
    expect(LogStats::of([], 3600))->toBe([
        'requests' => 0,
        'per_minute' => null,
        'server_errors' => 0,
        'error_rate' => null,
        'p50' => null,
        'p95' => null,
        'slowest' => [],
    ]);
});

test('with no window the rate is over the time the requests span', function () {
    expect(LogStats::of([logStatsRequest('/', 10, at: 1795521600.0), logStatsRequest('/', 10, at: 1795521720.0)], null)['per_minute'])->toBe(1.0);
});
