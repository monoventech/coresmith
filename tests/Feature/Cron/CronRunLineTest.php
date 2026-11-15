<?php

declare(strict_types=1);

use App\Models\MonitorAlert;
use App\Services\Cron\CronRunLine;
use Carbon\CarbonImmutable;

function cronLineAt(string $moment, string $timezone = 'UTC'): int
{
    return CarbonImmutable::parse($moment, $timezone)->getTimestamp();
}

test('each state reads as the card shows it, with the next run', function (array $state, ?MonitorAlert $alert, string $schedule, array $line) {
    expect(CronRunLine::for(['schedule' => $schedule], $state, cronLineAt('2026-11-15 14:05:40'), 'UTC', $alert, true))->toBe($line);
})->with([
    'succeeded' => [['last' => ['started' => cronLineAt('2026-11-15 14:05:00'), 'seconds' => 1, 'exit' => 0]], null, '* * * * *', ['state' => 'succeeded', 'text' => 'Ran at 2:05 PM in 1 second · next at 2:06 PM']],
    'failed' => [['last' => ['started' => cronLineAt('2026-11-15 06:00:00'), 'seconds' => 12, 'exit' => 1]], null, '0 6 * * *', ['state' => 'failed', 'text' => 'Failed at 6:00 AM after 12 seconds, exit code 1 · next tomorrow at 6:00 AM']],
    'missed' => [['last' => ['started' => cronLineAt('2026-11-08 03:00:01'), 'seconds' => 40, 'exit' => 0]], fn() => (new MonitorAlert)->forceFill(['level' => 'missed', 'peak' => cronLineAt('2026-11-15 03:00:00')]), '0 3 * * 0', ['state' => 'missed', 'text' => "Didn't run when due at 3:00 AM · next Sunday at 3:00 AM"]],
    'running' => [['last' => null, 'running' => cronLineAt('2026-11-15 14:00:02')], null, '*/15 * * * *', ['state' => 'running', 'text' => 'Running since 2:00 PM · next at 2:15 PM']],
    'never run' => [['last' => null], null, '*/15 * * * *', ['state' => 'none', 'text' => "Hasn't run yet · first at 2:15 PM"]],
]);

test('a run on another day names the day', function (string $started, string $text) {
    expect(CronRunLine::for(['schedule' => '0 3 * * *'], ['last' => ['started' => cronLineAt($started), 'seconds' => 300, 'exit' => 0]], cronLineAt('2026-11-15 14:05:40'), 'UTC', null, false)['text'])->toBe($text);
})->with([
    'yesterday' => ['2026-11-14 03:00:00', 'Ran yesterday at 3:00 AM in 5 minutes'],
    'last week' => ['2026-11-08 03:00:00', 'Ran Nov 8 at 3:00 AM in 5 minutes'],
]);

test('a paused app shows its last run without a next one', function () {
    expect(CronRunLine::for(['schedule' => '0 3 * * *'], ['last' => null], cronLineAt('2026-11-15 14:05:40'), 'UTC', null, false))->toBe(['state' => 'none', 'text' => "Hasn't run yet"]);
});

test('times are the server\'s own', function () {
    $line = CronRunLine::for(['schedule' => '0 3 * * *'], ['last' => ['started' => cronLineAt('2026-07-15 03:00:00', 'Europe/London'), 'seconds' => 2, 'exit' => 0]], cronLineAt('2026-07-15 09:00:00', 'Europe/London'), 'Europe/London', null, true);

    expect($line['text'])->toBe('Ran at 3:00 AM in 2 seconds · next tomorrow at 3:00 AM');
});

test('how long a run took reads in plain words', function (int $seconds, string $took) {
    expect(CronRunLine::took($seconds))->toBe($took);
})->with([
    [0, 'under a second'],
    [1, '1 second'],
    [119, '119 seconds'],
    [120, '2 minutes'],
    [7199, '119 minutes'],
    [7200, '2 hours'],
]);
