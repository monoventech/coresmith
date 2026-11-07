<?php

declare(strict_types=1);

use App\Enums\Plan;
use App\Enums\PlanFeature;
use App\Enums\ReadOnlyReason;

test('each plan includes exactly the features it sells', function (Plan $plan, PlanFeature $feature, bool $included) {
    expect($plan->includes($feature))->toBe($included);
})->with(function () {
    $included = [
        Plan::SelfHosted->value => PlanFeature::cases(),
        Plan::Business->value => PlanFeature::cases(),
        Plan::Pro->value => [PlanFeature::TeamRoles, PlanFeature::CustomDomain],
        Plan::Trial->value => [PlanFeature::TeamRoles, PlanFeature::CustomDomain],
        Plan::Starter->value => [],
    ];

    foreach (Plan::cases() as $plan) {
        foreach (PlanFeature::cases() as $feature) {
            yield "{$plan->value} {$feature->value}" => [$plan, $feature, in_array($feature, $included[$plan->value], true)];
        }
    }
});

test('limits follow the plan', function (Plan $plan, ?int $servers, ?int $members) {
    expect($plan->serverLimit())->toBe($servers)
        ->and($plan->memberLimit())->toBe($members);
})->with([
    'trial' => [Plan::Trial, 1, null],
    'starter' => [Plan::Starter, 3, 1],
    'pro' => [Plan::Pro, null, null],
    'business' => [Plan::Business, null, null],
    'self-hosted' => [Plan::SelfHosted, null, null],
]);

test('amounts come from config and only purchasable plans cost anything', function () {
    expect(Plan::Starter->amount())->toBe(9)
        ->and(Plan::Pro->amount())->toBe(19)
        ->and(Plan::Business->amount())->toBe(39)
        ->and(Plan::Trial->amount())->toBe(0)
        ->and(Plan::SelfHosted->amount())->toBe(0);

    config(['coresmith.billing.plans.pro.amount' => 21]);

    expect(Plan::Pro->amount())->toBe(21);
});

test('a plan can be on more than one price and any of them names it', function () {
    config(['coresmith.billing.plans.pro.prices' => 'price_new, price_old,']);

    expect(Plan::Pro->priceIds())->toBe(['price_new', 'price_old'])
        ->and(Plan::Pro->checkoutPrice())->toBe('price_new')
        ->and(Plan::fromPriceId('price_old'))->toBe(Plan::Pro)
        ->and(Plan::fromPriceId('price_new'))->toBe(Plan::Pro)
        ->and(Plan::fromPriceId('price_test_starter'))->toBe(Plan::Starter)
        ->and(Plan::fromPriceId('price_unknown'))->toBeNull()
        ->and(Plan::fromPriceId(null))->toBeNull()
        ->and(Plan::Trial->priceIds())->toBe([])
        ->and(Plan::SelfHosted->checkoutPrice())->toBeNull();
});

test('only starter, pro and business can be bought, in that order', function () {
    expect(Plan::purchasable())->toBe([Plan::Starter, Plan::Pro, Plan::Business]);
});

test('every plan has its label and summary', function () {
    expect(array_map(fn(Plan $plan): string => $plan->label(), Plan::cases()))->toBe(['Trial', 'Starter', 'Pro', 'Business', 'Self-hosted'])
        ->and(Plan::Starter->summary())->toBe('$9 per month · 3 servers · one person')
        ->and(Plan::Pro->summary())->toBe('$19 per month · unlimited servers · teammates and roles · custom domain')
        ->and(Plan::Business->summary())->toBe('$39 per month · everything in Pro · single sign-on · database backups to S3 · audit log export')
        ->and(Plan::SelfHosted->summary())->toBe('Free · every feature · you run the panel')
        ->and(Plan::Trial->summary())->toBe('Pro features on 1 server for 14 days');

    config(['coresmith.billing.trial_days' => 30]);

    expect(Plan::Trial->summary())->toBe('Pro features on 1 server for 30 days');
});

test('every feature says which plans have it', function () {
    expect(array_map(fn(PlanFeature $feature): string => $feature->note(), PlanFeature::cases()))->toBe([
        'Teammates and roles are part of Pro and Business.',
        'Custom domains are part of Pro and Business.',
        'Single sign-on is part of Business.',
        'Database backups to S3 are part of Business.',
        'Audit log export is part of Business.',
    ]);
});

test('every read-only reason says why', function () {
    expect(array_map(fn(ReadOnlyReason $reason): string => $reason->title(), ReadOnlyReason::cases()))->toBe([
        'This team has no plan yet, so it is read-only.',
        "This team's trial has ended, so it is read-only.",
        "This team's plan has ended, so it is read-only.",
        "This team's payments failed, so it is read-only.",
        "This team's free plan has ended, so it is read-only.",
    ]);
});

test('a plan can state a price other than the configured one', function () {
    expect(Plan::Starter->summary('$7'))->toBe('$7 per month · 3 servers · one person')
        ->and(Plan::Pro->summary('$15'))->toBe('$15 per month · unlimited servers · teammates and roles · custom domain')
        ->and(Plan::Business->summary('$29'))->toBe('$29 per month · everything in Pro · single sign-on · database backups to S3 · audit log export');
});
