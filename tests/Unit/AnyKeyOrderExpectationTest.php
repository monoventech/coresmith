<?php

declare(strict_types=1);

use PHPUnit\Framework\ExpectationFailedException;

test('stored json matches the same keys and values in any key order', function () {
    expect(['to' => 'admin', 'from' => 'member', 'jobs' => [['schedule' => '* * * * *', 'command' => 'php artisan schedule:run']]])
        ->toBeInAnyKeyOrder(['jobs' => [['command' => 'php artisan schedule:run', 'schedule' => '* * * * *']], 'from' => 'member', 'to' => 'admin']);
});

test('stored json still differs on a missing, extra or changed key, another type or a list in another order', function (array $actual) {
    expect(fn() => expect($actual)->toBeInAnyKeyOrder(['from' => 'member', 'to' => 'admin', 'jobs' => [1, 2]]))
        ->toThrow(ExpectationFailedException::class);
})->with([
    'a missing key' => [['from' => 'member', 'jobs' => [1, 2]]],
    'an extra key' => [['from' => 'member', 'to' => 'admin', 'jobs' => [1, 2], 'by' => 'ada']],
    'a changed value' => [['from' => 'member', 'to' => 'owner', 'jobs' => [1, 2]]],
    'another type' => [['from' => 'member', 'to' => 'admin', 'jobs' => ['1', 2]]],
    'a list in another order' => [['from' => 'member', 'to' => 'admin', 'jobs' => [2, 1]]],
]);
