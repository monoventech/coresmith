<?php

declare(strict_types=1);

use App\Services\Runtime\RuntimeLifecycle;
use Carbon\CarbonImmutable;

test('a version is active, on security fixes only, or past its end of life by the calendar', function (string $now, string $state, string $label) {
    $this->travelTo(CarbonImmutable::parse($now));

    $status = app(RuntimeLifecycle::class)->status('php', '8.4.25');

    expect($status->state)->toBe($state)->and($status->label())->toBe($label);
})->with([
    'active' => ['2026-09-25', 'active', 'Active until Dec 2026'],
    'security fixes only' => ['2027-03-01', 'maintenance', 'Security fixes until Dec 2028'],
    'end of life' => ['2029-01-02', 'eol', 'End of life'],
]);

test('the last day of support still counts as supported', function () {
    $this->travelTo(CarbonImmutable::parse('2026-12-31 23:00'));

    expect(app(RuntimeLifecycle::class)->status('php', '8.4.25')->state)->toBe('active');
});

test('bun and deno publish no support schedule, so they carry no status', function (string $runtime, string $version) {
    $status = app(RuntimeLifecycle::class)->status($runtime, $version);

    expect($status->state)->toBe('unknown')->and($status->label())->toBe('');
})->with([['bun', '1.4.2'], ['deno', '2.9.7']]);

test('a release candidate is a pre-release, and a version outside the catalog is unknown', function () {
    expect(app(RuntimeLifecycle::class)->status('php', '8.6.0RC1')->state)->toBe('pre-release')
        ->and(app(RuntimeLifecycle::class)->status('php', '7.4.33')->state)->toBe('unknown');
});

test('each option in the picker carries its status', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-25'));

    $options = app(RuntimeLifecycle::class)->options('php');

    expect($options[0])->toBe(['line' => '8.5', 'version' => '8.5.10', 'status' => ['state' => 'active', 'label' => 'Active until Dec 2027', 'tone' => 'success']])
        ->and($options[4]['status'])->toBe(['state' => 'eol', 'label' => 'End of life', 'tone' => 'destructive']);
});
