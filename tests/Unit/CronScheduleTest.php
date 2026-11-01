<?php

declare(strict_types=1);

use App\Services\Panel\CronSchedule;

test('a schedule with one obvious sentence is said in words', function (string $schedule, string $sentence) {
    expect(CronSchedule::sentence($schedule))->toBe($sentence);
})->with([
    ['* * * * *', 'Every minute'],
    ['*/1 * * * *', 'Every minute'],
    ['*/5 * * * *', 'Every 5 minutes'],
    ['0 * * * *', 'Every hour'],
    ['0 */1 * * *', 'Every hour'],
    ['15 * * * *', 'Every hour at :15'],
    ['5 * * * *', 'Every hour at :05'],
    ['0 */6 * * *', 'Every 6 hours'],
    ['30 */2 * * *', 'Every 2 hours at :30'],
    ['0 3 * * *', 'Daily at 3:00 AM UTC'],
    ['0 0 * * *', 'Daily at 12:00 AM UTC'],
    ['30 12 * * *', 'Daily at 12:30 PM UTC'],
    ['5 17 * * *', 'Daily at 5:05 PM UTC'],
    ['30 8 * * 1', 'Weekly on Monday at 8:30 AM UTC'],
    ['0 9 * * 0', 'Weekly on Sunday at 9:00 AM UTC'],
    ['0 9 * * 7', 'Weekly on Sunday at 9:00 AM UTC'],
    ['0 9 * * 1-5', 'Weekdays at 9:00 AM UTC'],
    ['0 2 1 * *', 'Monthly on the 1st at 2:00 AM UTC'],
    ['0 2 2 * *', 'Monthly on the 2nd at 2:00 AM UTC'],
    ['0 2 3 * *', 'Monthly on the 3rd at 2:00 AM UTC'],
    ['0 2 11 * *', 'Monthly on the 11th at 2:00 AM UTC'],
    ['0 2 22 * *', 'Monthly on the 22nd at 2:00 AM UTC'],
    ['0 2 31 * *', 'Monthly on the 31st at 2:00 AM UTC'],
]);

test('anything else is a custom schedule with no sentence', function (string $schedule) {
    expect(CronSchedule::sentence($schedule))->toBeNull()
        ->and(CronSchedule::phrase($schedule))->toBe('on a custom schedule');
})->with(['15 9-17 * * 1-5', '0 0 1 1 *', '*/5 9-17 * * *', '0 3 1 * 1', '0,30 * * * *', '0 3 * * 6,0', '61 * * * *', 'every day']);

test('a sentence reads inside another one', function () {
    expect(CronSchedule::phrase('0 3 * * *'))->toBe('daily at 3:00 AM UTC')
        ->and(CronSchedule::phrase('*/5 * * * *'))->toBe('every 5 minutes');
});

test('a schedule opens the dialog on the preset it matches exactly', function (string $schedule, array $preset) {
    expect(CronSchedule::preset($schedule))->toBe($preset);
})->with([
    'every minute' => ['* * * * *', ['kind' => 'minute', 'minute' => 0, 'hour' => 3, 'weekday' => 1]],
    'hourly' => ['15 * * * *', ['kind' => 'hourly', 'minute' => 15, 'hour' => 3, 'weekday' => 1]],
    'daily' => ['30 4 * * *', ['kind' => 'daily', 'minute' => 30, 'hour' => 4, 'weekday' => 1]],
    'weekly' => ['30 8 * * 5', ['kind' => 'weekly', 'minute' => 30, 'hour' => 8, 'weekday' => 5]],
    'weekly on a sunday written as 7' => ['0 9 * * 7', ['kind' => 'weekly', 'minute' => 0, 'hour' => 9, 'weekday' => 0]],
    'every five minutes' => ['*/5 * * * *', ['kind' => 'custom', 'minute' => 0, 'hour' => 3, 'weekday' => 1]],
    'weekdays' => ['0 9 * * 1-5', ['kind' => 'custom', 'minute' => 0, 'hour' => 3, 'weekday' => 1]],
    'not a schedule' => ['61 * * * *', ['kind' => 'custom', 'minute' => 0, 'hour' => 3, 'weekday' => 1]],
]);
