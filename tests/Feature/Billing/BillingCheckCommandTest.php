<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Laravel\Cashier\Cashier;
use Tests\Support\CloudBilling;
use Tests\Support\FakeStripe;

function billingCheckPrice(string $id, int $amount, array $overrides = []): array
{
    return array_replace_recursive([
        'id' => $id,
        'object' => 'price',
        'active' => true,
        'currency' => 'usd',
        'unit_amount' => $amount,
        'recurring' => ['interval' => 'month', 'interval_count' => 1, 'usage_type' => 'licensed'],
    ], $overrides);
}

function billingCheckEndpoint(array $overrides = []): array
{
    return array_replace([
        'id' => 'we_1',
        'object' => 'webhook_endpoint',
        'url' => route('cashier.webhook'),
        'status' => 'enabled',
        'api_version' => Cashier::STRIPE_VERSION,
        'enabled_events' => config('cashier.webhook.events'),
    ], $overrides);
}

function billingCheckStripe(array $prices = [], array $endpoints = []): void
{
    $prices += [
        'price_test_starter' => billingCheckPrice('price_test_starter', 900),
        'price_test_pro' => billingCheckPrice('price_test_pro', 1900),
        'price_test_business' => billingCheckPrice('price_test_business', 3900),
    ];

    $fakes = [FakeStripe::url('webhook_endpoints*') => Http::response(['object' => 'list', 'data' => $endpoints === [] ? [billingCheckEndpoint()] : $endpoints, 'has_more' => false])];

    foreach ($prices as $id => $price) {
        $fakes[FakeStripe::url("prices/{$id}*")] = Http::response($price);
    }

    FakeStripe::fake($fakes);
}

/**
 * @return array{0: int, 1: string}
 */
function billingCheck(array $options = []): array
{
    $code = Artisan::call('coresmith:billing-check', $options);

    return [$code, Artisan::output()];
}

beforeEach(function () {
    CloudBilling::enable();
    config(['cashier.key' => 'pk_test_coresmith_fake_key', 'cashier.secret' => FakeStripe::SECRET, 'cashier.webhook.secret' => FakeStripe::WEBHOOK_SECRET]);
    config(['mail.default' => 'smtp', 'mail.mailers.smtp.username' => 'mailer-login-e2e', 'mail.mailers.smtp.password' => 'mailer-password-e2e']);
});

test('a panel ready for stripe passes every check', function () {
    billingCheckStripe();

    [$code, $output] = billingCheck(['--live' => true]);

    expect($code)->toBe(0)
        ->and($output)->toContain('✓ Coresmith runs as Coresmith Cloud.')
        ->and($output)->toContain('✓ STRIPE_KEY is set.')
        ->and($output)->toContain('✓ STRIPE_SECRET is set.')
        ->and($output)->toContain('✓ STRIPE_WEBHOOK_SECRET is set.')
        ->and($output)->toContain('✓ Starter has a price.')
        ->and($output)->toContain('✓ Pro has a price.')
        ->and($output)->toContain('✓ Business has a price.')
        ->and($output)->toContain('✓ Starter costs $9 a month in Stripe.')
        ->and($output)->toContain('✓ Pro costs $19 a month in Stripe.')
        ->and($output)->toContain('✓ Business costs $39 a month in Stripe.')
        ->and($output)->toContain('✓ Stripe sends every event the panel needs to ' . route('cashier.webhook') . '.')
        ->and($output)->toContain('✓ MAIL_MAILER is smtp, so the panel can send email.')
        ->and($output)->not->toContain('✗')
        ->and($output)->not->toContain(FakeStripe::SECRET)
        ->and($output)->not->toContain(FakeStripe::WEBHOOK_SECRET)
        ->and($output)->not->toContain('pk_test_coresmith_fake_key')
        ->and($output)->not->toContain('mailer-login-e2e')
        ->and($output)->not->toContain('mailer-password-e2e');
});

test('a panel whose mail goes nowhere fails the check', function (string $mailer) {
    config(['mail.default' => $mailer]);
    FakeStripe::fake();

    [$code, $output] = billingCheck();

    expect($code)->toBe(1)
        ->and($output)->toContain("✗ MAIL_MAILER is {$mailer}, so sign-up, invitation and billing emails are never delivered. Set MAIL_MAILER and the other MAIL_* settings.")
        ->and($output)->toContain('✓ Pro has a price.')
        ->and($output)->not->toContain('mailer-login-e2e')
        ->and($output)->not->toContain('mailer-password-e2e');
})->with(['log', 'array']);

test('a panel with no mailer, or one it does not know, fails the check', function (?string $mailer, string $line) {
    config(['mail.default' => $mailer]);
    FakeStripe::fake();

    [$code, $output] = billingCheck();

    expect($code)->toBe(1)
        ->and($output)->toContain($line)
        ->and($output)->not->toContain('✓ MAIL_MAILER')
        ->and($output)->not->toContain('mailer-login-e2e')
        ->and($output)->not->toContain('mailer-password-e2e');
})->with([
    'none set' => [null, '✗ MAIL_MAILER is not set, so sign-up, invitation and billing emails are never delivered. Set MAIL_MAILER and the other MAIL_* settings.'],
    'an empty name' => ['', '✗ MAIL_MAILER is not set, so sign-up, invitation and billing emails are never delivered. Set MAIL_MAILER and the other MAIL_* settings.'],
    'a name with a typo' => ['stmp', '✗ MAIL_MAILER is stmp, which is not a mailer this panel knows, so no email can be sent. Set it to a configured mailer such as smtp.'],
]);

test('without --live nothing is asked of stripe', function () {
    FakeStripe::fake();

    [$code, $output] = billingCheck();

    expect($code)->toBe(0)
        ->and($output)->toContain('✓ Pro has a price.')
        ->and($output)->not->toContain('a month in Stripe');

    Http::assertNothingSent();
});

test('a missing secret fails the check', function (string $config, string $line) {
    config([$config => null]);
    FakeStripe::fake();

    [$code, $output] = billingCheck();

    expect($code)->toBe(1)
        ->and($output)->toContain($line);
})->with([
    'the publishable key' => ['cashier.key', '✗ STRIPE_KEY is missing.'],
    'the secret key' => ['cashier.secret', '✗ STRIPE_SECRET is missing.'],
    'the webhook secret' => ['cashier.webhook.secret', '✗ STRIPE_WEBHOOK_SECRET is missing.'],
]);

test('a plan without a price fails the check', function () {
    config(['coresmith.billing.plans.pro.prices' => null]);
    FakeStripe::fake();

    [$code, $output] = billingCheck();

    expect($code)->toBe(1)
        ->and($output)->toContain('✗ STRIPE_PRICE_PRO is missing.')
        ->and($output)->toContain('✓ Business has a price.');
});

test('a price that disagrees with the panel is named', function () {
    billingCheckStripe(['price_test_pro' => billingCheckPrice('price_test_pro', 2500)]);

    [$code, $output] = billingCheck(['--live' => true]);

    expect($code)->toBe(1)
        ->and($output)->toContain('✗ Pro costs $25 a month in Stripe, but the panel says $19.');
});

test('a price stripe cannot bill monthly in dollars is named', function (array $overrides, string $line) {
    billingCheckStripe(['price_test_business' => billingCheckPrice('price_test_business', 3900, $overrides)]);

    [$code, $output] = billingCheck(['--live' => true]);

    expect($code)->toBe(1)
        ->and($output)->toContain($line);
})->with([
    'archived' => [['active' => false], '✗ Business uses price_test_business, which is archived in Stripe.'],
    'yearly' => [['recurring' => ['interval' => 'year']], '✗ Business uses price_test_business, which Stripe bills every year, not every month.'],
    'in euros' => [['currency' => 'eur'], '✗ Business uses price_test_business, which is in EUR, not USD.'],
]);

test('a price stripe does not know is named', function () {
    FakeStripe::fake([
        FakeStripe::url('prices/price_test_starter*') => FakeStripe::error('invalid_request_error', 'No such price: price_test_starter', 404, 'resource_missing'),
        FakeStripe::url('prices/*') => Http::response(billingCheckPrice('price_test_pro', 1900)),
        FakeStripe::url('webhook_endpoints*') => Http::response(['object' => 'list', 'data' => [billingCheckEndpoint()], 'has_more' => false]),
    ]);

    [$code, $output] = billingCheck(['--live' => true]);

    expect($code)->toBe(1)
        ->and($output)->toContain('✗ Stripe has no price price_test_starter for Starter.');
});

test('an endpoint missing an event names it', function () {
    billingCheckStripe(endpoints: [billingCheckEndpoint(['enabled_events' => array_values(array_diff(config('cashier.webhook.events'), ['invoice.paid']))])]);

    [$code, $output] = billingCheck(['--live' => true]);

    expect($code)->toBe(1)
        ->and($output)->toContain('✗ The Stripe webhook to ' . route('cashier.webhook') . ' does not send invoice.paid.');
});

test('an endpoint that sends every event passes', function () {
    billingCheckStripe(endpoints: [billingCheckEndpoint(['enabled_events' => ['*']])]);

    [$code] = billingCheck(['--live' => true]);

    expect($code)->toBe(0);
});

test('an endpoint on another api version is named', function () {
    billingCheckStripe(endpoints: [billingCheckEndpoint(['api_version' => '2024-06-20'])]);

    [$code, $output] = billingCheck(['--live' => true]);

    expect($code)->toBe(1)
        ->and($output)->toContain('✗ The Stripe webhook to ' . route('cashier.webhook') . ' uses API version 2024-06-20, but the panel expects ' . Cashier::STRIPE_VERSION . '.');
});

test('a panel with no endpoint is told how to make one', function () {
    billingCheckStripe(endpoints: [billingCheckEndpoint(['url' => 'https://elsewhere.example.com/stripe/webhook'])]);

    [$code, $output] = billingCheck(['--live' => true]);

    expect($code)->toBe(1)
        ->and($output)->toContain('✗ No Stripe webhook sends to ' . route('cashier.webhook') . '. Run php artisan cashier:webhook.');
});

test('a disabled endpoint is named', function () {
    billingCheckStripe(endpoints: [billingCheckEndpoint(['status' => 'disabled'])]);

    [$code, $output] = billingCheck(['--live' => true]);

    expect($code)->toBe(1)
        ->and($output)->toContain('✗ The Stripe webhook to ' . route('cashier.webhook') . ' is disabled. Turn it on in the Stripe dashboard.');
});

test('self-hosted is not set up for billing', function () {
    config(['coresmith.edition' => 'self-hosted']);
    FakeStripe::fake();

    [$code, $output] = billingCheck(['--live' => true]);

    expect($code)->toBe(1)
        ->and($output)->toContain('✗ CORESMITH_EDITION is not cloud, so billing is off.');

    Http::assertNothingSent();
});
