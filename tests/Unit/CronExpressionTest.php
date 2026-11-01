<?php

declare(strict_types=1);

use App\Services\Deployment\CronExpression;

test('a schedule cron reads as written has no problem', function (string $schedule) {
    expect(CronExpression::problem($schedule))->toBeNull();
})->with([
    'every minute' => '* * * * *',
    'a step' => '*/5 * * * *',
    'weekdays' => '0 3 * * 1-5',
    'lists and ranges' => '0,30 9-17 1 1,6 0',
    'every top value' => '59 23 31 12 7',
    'a stepped range' => '0-59/15 * * * *',
    'a leading zero' => '05 * * * *',
    'both sundays' => '* * * * 0-7',
    'steps as long as each field' => '*/59 */23 */31 */12 */7',
]);

test('a schedule cron would refuse is named field by field with what to write instead', function (string $schedule, string $problem) {
    expect(CronExpression::problem($schedule))->toBe($problem);
})->with([
    'a minute past 59' => ['61 * * * *', "61 isn't a minute. Minutes run from 0 to 59."],
    'an hour past 23' => ['0 24 * * *', "24 isn't an hour. Hours run from 0 to 23."],
    'day 0 of the month' => ['0 0 0 * *', "0 isn't a day of the month. Days of the month run from 1 to 31."],
    'month 13' => ['0 0 * 13 *', "13 isn't a month. Months run from 1 to 12."],
    'weekday 8' => ['0 0 * * 8', "8 isn't a day of the week. Days of the week run from 0 to 7. 0 and 7 are both Sunday."],
    'the end of a range' => ['0 9-25 * * *', "25 isn't an hour. Hours run from 0 to 23."],
    'a huge number' => ['99999999999 * * * *', "99999999999 isn't a minute. Minutes run from 0 to 59."],
    'a backwards range' => ['5-1 * * * *', '5-1 runs backwards, so it never matches. Put the smaller number first.'],
    'a zero step' => ['*/0 * * * *', 'A step of 0 never moves on. Use 1 or more, like */5.'],
    'a step after a single number' => ['5/10 * * * *', '5/10 has a step but no range. Write */10 or 5-59/10 instead.'],
    'a step longer than the field' => ['*/90 * * * *', '/90 is too big a step for minutes. Use up to 59.'],
    'an empty list item' => ['1,,2 * * * *', 'The minute field has an empty item. Remove the extra comma.'],
    'half a range' => ['1- * * * *', "1- isn't something cron can read in the minute field. Use a number, a range like 1-5, a list like 1,15 or a step like */10."],
    'words' => ['every day', 'Write five fields separated by single spaces, using only digits and * / , - in each, such as */5 * * * *.'],
    'a trailing newline' => ["* * * * *\n", 'Write five fields separated by single spaces, using only digits and * / , - in each, such as */5 * * * *.'],
    'too long' => [str_repeat('1,', 60) . '1 * * * *', 'That schedule is too long. Cron schedules are five short fields, like */5 * * * *.'],
]);

test('the first problem from the left is the one named', function () {
    expect(CronExpression::problem('61 24 * * *'))->toBe("61 isn't a minute. Minutes run from 0 to 59.");
});
