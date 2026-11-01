<?php

declare(strict_types=1);

use App\Services\Panel\Formats;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-13 14:05:09'));
});

test('memory is shown in megabytes below a gigabyte and whole gigabytes above', function (?int $megabytes, ?string $expected) {
    expect(Formats::memory($megabytes))->toBe($expected);
})->with([
    'unknown' => [null, null],
    'zero' => [0, null],
    'negative' => [-512, null],
    'half a gigabyte' => [512, '512 MB'],
    'one gigabyte' => [1024, '1 GB'],
    'rounded' => [1536, '2 GB'],
    'eight gigabytes' => [7963, '8 GB'],
]);

test('uptime is counted in days once a day has passed and in hours before that', function (?string $bootedAt, ?string $expected) {
    expect(Formats::uptime($bootedAt === null ? null : CarbonImmutable::parse($bootedAt)))->toBe($expected);
})->with([
    'unknown' => [null, null],
    'just booted' => ['2026-09-13 14:00:00', 'up 1 hour'],
    'one hour' => ['2026-09-13 13:05:09', 'up 1 hour'],
    'five hours' => ['2026-09-13 09:00:00', 'up 5 hours'],
    'one day' => ['2026-09-12 14:05:09', 'up 1 day'],
    'many days' => ['2026-08-01 00:00:00', 'up 43 days'],
]);

test('a clock time is shown for today and a date for anything earlier', function (?string $moment, ?string $expected) {
    expect(Formats::clock($moment === null ? null : CarbonImmutable::parse($moment)))->toBe($expected);
})->with([
    'unknown' => [null, null],
    'today' => ['2026-09-13 09:07:00', '09:07'],
    'yesterday' => ['2026-09-12 23:59:00', 'Sep 12'],
]);

test('a relative moment reads as now, today or a date', function (?string $moment, ?string $expected) {
    expect(Formats::when($moment === null ? null : CarbonImmutable::parse($moment)))->toBe($expected);
})->with([
    'unknown' => [null, null],
    'seconds ago' => ['2026-09-13 14:04:30', 'now'],
    'earlier today' => ['2026-09-13 08:15:00', 'today 08:15'],
    'another day' => ['2026-09-02 08:15:00', 'Sep 2'],
]);

test('an absolute moment carries the date, time and zone', function () {
    expect(Formats::absolute(CarbonImmutable::parse('2026-09-02 08:15:00')))->toBe('Sep 2, 2026 at 08:15 UTC')
        ->and(Formats::absolute(null))->toBeNull();
});

test('a duration is shown in seconds under a minute and minutes with padded seconds above', function (?string $from, ?string $to, ?string $expected) {
    expect(Formats::duration(
        $from === null ? null : CarbonImmutable::parse($from),
        $to === null ? null : CarbonImmutable::parse($to),
    ))->toBe($expected);
})->with([
    'no start' => [null, '2026-09-13 14:00:00', null],
    'no end' => ['2026-09-13 14:00:00', null, null],
    'instant' => ['2026-09-13 14:00:00', '2026-09-13 14:00:00', '0s'],
    'under a minute' => ['2026-09-13 14:00:00', '2026-09-13 14:00:59', '59s'],
    'a minute' => ['2026-09-13 14:00:00', '2026-09-13 14:01:00', '1m 00s'],
    'padded' => ['2026-09-13 14:00:00', '2026-09-13 14:02:05', '2m 05s'],
    'backwards' => ['2026-09-13 14:02:00', '2026-09-13 14:00:00', '0s'],
]);

test('a long fingerprint is shortened to its head and tail', function (?string $fingerprint, ?string $expected) {
    expect(Formats::fingerprint($fingerprint))->toBe($expected);
})->with([
    'unknown' => [null, null],
    'already short' => ['SHA256:abcdefghijkl', 'SHA256:abcdefghijkl'],
    'full length' => ['SHA256:Xl/jKyuvKrFTpjRJe7mfKlKvBCANaqbn86pG1sf2G4k', 'SHA256:Xl/j…2G4k'],
]);

test('a compact release timestamp is split into date and time', function (?string $release, ?string $expected) {
    expect(Formats::release($release))->toBe($expected);
})->with([
    'unknown' => [null, null],
    'compact' => ['20260913140509', '20260913-140509'],
    'already split' => ['20260913-140509', '20260913-140509'],
    'not a timestamp' => ['v2.4.0', 'v2.4.0'],
]);

test('a price is shown with its currency symbol, dropping whole cents', function (?string $amount, ?string $currency, ?string $money, ?string $monthly) {
    expect(Formats::money($amount, $currency))->toBe($money)
        ->and(Formats::monthly($amount, $currency))->toBe($monthly);
})->with([
    'euros with cents' => ['4.35', 'EUR', '€4.35', '€4.35/mo'],
    'whole dollars' => ['12.00', 'USD', '$12', '$12/mo'],
    'pounds with cents' => ['9.50', 'GBP', '£9.50', '£9.50/mo'],
    'a currency with no symbol keeps its code and cents' => ['12.00', 'CHF', '12.00 CHF', '12.00 CHF/mo'],
    'a lower-case code' => ['5', 'eur', '€5', '€5/mo'],
    'no amount' => [null, 'EUR', null, null],
    'no currency' => ['4.35', null, null, null],
    'an amount that is not a number' => ['n/a', 'EUR', null, null],
]);
