<?php

declare(strict_types=1);

use App\Services\Cron\CronDue;
use Carbon\CarbonImmutable;

function cronDueAt(string $moment, string $timezone = 'UTC'): int
{
    return CarbonImmutable::parse($moment, $timezone)->getTimestamp();
}

test('a job that started at its due time was not missed', function (string $schedule, string $due, string $now) {
    $state = ['seen' => cronDueAt('2026-11-01 00:00:00'), 'last' => ['started' => cronDueAt($due) + 1, 'seconds' => 2, 'exit' => 0], 'running' => null];

    expect(CronDue::missed($schedule, $state, cronDueAt($now), 'UTC'))->toBeNull();
})->with([
    'every minute' => ['* * * * *', '2026-11-15 10:03:00', '2026-11-15 10:05:30'],
    'every 15 minutes' => ['*/15 * * * *', '2026-11-15 10:00:00', '2026-11-15 10:02:30'],
    'daily' => ['0 3 * * *', '2026-11-15 03:00:00', '2026-11-15 09:00:00'],
    'weekly' => ['0 3 * * 0', '2026-11-15 03:00:00', '2026-11-16 09:00:00'],
]);

test('a job with no run since its due time, two minutes on, was missed at that due time', function (string $schedule, string $last, string $now, string $due) {
    $state = ['seen' => cronDueAt('2026-11-01 00:00:00'), 'last' => ['started' => cronDueAt($last), 'seconds' => 2, 'exit' => 0], 'running' => null];

    expect(CronDue::missed($schedule, $state, cronDueAt($now), 'UTC'))->toBe(cronDueAt($due));
})->with([
    'every minute' => ['* * * * *', '2026-11-15 10:01:00', '2026-11-15 10:05:30', '2026-11-15 10:03:00'],
    'daily' => ['0 3 * * *', '2026-11-14 03:00:01', '2026-11-15 03:02:00', '2026-11-15 03:00:00'],
]);

test('a due time inside the two minutes of grace is not judged yet', function () {
    $state = ['seen' => cronDueAt('2026-11-01 00:00:00'), 'last' => ['started' => cronDueAt('2026-11-14 03:00:01'), 'seconds' => 2, 'exit' => 0], 'running' => null];

    expect(CronDue::missed('0 3 * * *', $state, cronDueAt('2026-11-15 03:01:59'), 'UTC'))->toBeNull();
});

test('a job that is still running from its due time was not missed, however long it takes', function () {
    $state = ['seen' => cronDueAt('2026-11-01 00:00:00'), 'last' => ['started' => cronDueAt('2026-11-14 03:00:01'), 'seconds' => 600, 'exit' => 0], 'running' => cronDueAt('2026-11-15 03:00:01')];

    expect(CronDue::missed('0 3 * * *', $state, cronDueAt('2026-11-15 03:09:00'), 'UTC'))->toBeNull();
});

test('a due time before Coresmith first saw the job, before the crontab was last written, or before the server booted is not a miss', function (array $state, int $since) {
    expect(CronDue::missed('0 3 * * *', $state, cronDueAt('2026-11-15 09:00:00'), 'UTC', $since))->toBeNull();
})->with([
    'a job added after the due time' => [['seen' => cronDueAt('2026-11-15 08:00:00'), 'last' => null, 'running' => null], 0],
    'a crontab written after the due time' => [['seen' => cronDueAt('2026-11-01 00:00:00'), 'last' => null, 'running' => null], cronDueAt('2026-11-15 05:00:00')],
    'a server booted after the due time' => [['seen' => cronDueAt('2026-11-01 00:00:00'), 'last' => null, 'running' => null], cronDueAt('2026-11-15 04:30:00')],
]);

test('a job that never ran is missed at its first due time after Coresmith saw it', function () {
    $state = ['seen' => cronDueAt('2026-11-14 12:00:00'), 'last' => null, 'running' => null];

    expect(CronDue::missed('0 3 * * *', $state, cronDueAt('2026-11-15 09:00:00'), 'UTC'))->toBe(cronDueAt('2026-11-15 03:00:00'));
});

test('due times follow the server timezone, as cron does', function () {
    $state = ['seen' => cronDueAt('2026-06-01 00:00:00'), 'last' => ['started' => cronDueAt('2026-07-15 03:00:01', 'Europe/London'), 'seconds' => 2, 'exit' => 0], 'running' => null];
    $now = cronDueAt('2026-07-15 04:30:00', 'Europe/London');

    expect(CronDue::missed('0 3 * * *', $state, $now, 'Europe/London'))->toBeNull()
        ->and(CronDue::missed('0 3 * * *', $state, $now, 'UTC'))->toBe(cronDueAt('2026-07-15 03:00:00', 'UTC'))
        ->and(CronDue::next('0 3 * * *', $now, 'Europe/London'))->toBe(cronDueAt('2026-07-16 03:00:00', 'Europe/London'));
});
