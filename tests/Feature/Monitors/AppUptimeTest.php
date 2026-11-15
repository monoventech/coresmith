<?php

declare(strict_types=1);

use App\Models\AppCheckDay;
use App\Models\Application;
use App\Services\Monitors\AppUptime;
use Carbon\CarbonImmutable;

function appUptimeDay(Application $application, string $day, int $checks, int $answered, ?string $failure = null): void
{
    AppCheckDay::query()->insert(['application_id' => $application->id, 'day' => $day, 'checks' => $checks, 'answered' => $answered, 'last_failure_at' => $failure, 'created_at' => now(), 'updated_at' => now()]);
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-15 09:30:00'));
});

test('an app that was never checked has no uptime to show', function () {
    expect(app(AppUptime::class)->for(Application::factory()->create()))->toBeNull();
});

test('thirty days run oldest first, grey before the first check, and the percentage counts only checked days', function () {
    $application = Application::factory()->create();
    appUptimeDay($application, '2026-11-13', 288, 288);
    appUptimeDay($application, '2026-11-14', 288, 286, '2026-11-14 01:40:00');
    appUptimeDay($application, '2026-11-15', 100, 40, '2026-11-15 09:25:00');
    appUptimeDay($application, '2026-10-01', 288, 0);

    $uptime = app(AppUptime::class)->for($application);

    expect($uptime['days'])->toHaveCount(30)
        ->and($uptime['days'][0]['date'])->toBe('2026-10-17')
        ->and($uptime['days'][29]['date'])->toBe('2026-11-15')
        ->and(array_count_values(array_column($uptime['days'], 'state')))->toBe(['none' => 27, 'full' => 1, 'gaps' => 1, 'down' => 1])
        ->and(array_slice(array_column($uptime['days'], 'title'), -3))->toBe(['Nov 13: every check answered', 'Nov 14: 286 of 288 checks answered', 'Nov 15: 40 of 100 checks answered'])
        ->and($uptime['percent'])->toBe('90.82%')
        ->and($uptime['label'])->toBe('Answering on each of the last 30 days: 1 with every check answered, 1 with some failed checks, 1 with most checks failing, 27 not checked')
        ->and($uptime['note'])->toBe('Coresmith checks it every 5 minutes. Last failed check today at 09:25.');
});

test('every check answered reads 100% with no failed checks', function () {
    $application = Application::factory()->create();
    appUptimeDay($application, '2026-11-15', 108, 108);

    $uptime = app(AppUptime::class)->for($application);

    expect($uptime['percent'])->toBe('100%')
        ->and($uptime['note'])->toBe('Coresmith checks it every 5 minutes. No failed checks in 30 days.');
});

test('a percentage is cut, never rounded up to look whole', function () {
    $application = Application::factory()->create();
    appUptimeDay($application, '2026-11-14', 9999, 9998, '2026-11-14 22:05:00');

    $uptime = app(AppUptime::class)->for($application);

    expect($uptime['percent'])->toBe('99.98%')
        ->and($uptime['note'])->toBe('Coresmith checks it every 5 minutes. Last failed check yesterday at 22:05.');
});

test('an older failure is named by its date', function () {
    $application = Application::factory()->create();
    appUptimeDay($application, '2026-11-02', 288, 287, '2026-11-02 04:10:00');

    expect(app(AppUptime::class)->for($application)['note'])->toBe('Coresmith checks it every 5 minutes. Last failed check on Nov 2 at 04:10.');
});
