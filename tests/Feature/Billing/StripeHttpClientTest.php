<?php

declare(strict_types=1);

use App\Providers\BillingServiceProvider;
use App\Services\Billing\StripeHttpClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Cashier\Cashier;
use Stripe\BaseStripeClient;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\CardException;
use Stripe\Exception\InvalidRequestException;
use Stripe\Exception\UnexpectedValueException;
use Stripe\Stripe;
use Tests\Support\FakeStripe;

afterEach(function () {
    Stripe::setMaxNetworkRetries(0);
    Cashier::$apiBaseUrl = BaseStripeClient::DEFAULT_API_BASE;
});

test('a get carries the parameters in the query and comes back as stripe objects', function () {
    FakeStripe::fake([FakeStripe::url('customers/cus_1*') => Http::response(FakeStripe::customer('cus_1'), 200, ['Request-Id' => 'req_1'])]);

    $customer = Cashier::stripe()->customers->retrieve('cus_1', ['expand' => ['invoice_settings.default_payment_method']]);

    expect($customer->id)->toBe('cus_1')
        ->and($customer->getLastResponse()->headers['Request-Id'])->toBe('req_1');

    Http::assertSent(fn(Request $request): bool => $request->method() === 'GET'
        && str_contains($request->url(), 'expand%5B0%5D=invoice_settings.default_payment_method')
        && $request->hasHeader('Authorization', 'Bearer ' . FakeStripe::SECRET));
});

test('a post is form encoded with nested parameters', function () {
    FakeStripe::fake([FakeStripe::url('customers') => FakeStripe::customer('cus_2')]);

    Cashier::stripe()->customers->create(['name' => 'Monoven Ltd', 'metadata' => ['team_id' => 'team-1']]);

    Http::assertSent(fn(Request $request): bool => $request->method() === 'POST'
        && $request->hasHeader('Content-Type', 'application/x-www-form-urlencoded')
        && $request['name'] === 'Monoven Ltd'
        && $request['metadata']['team_id'] === 'team-1');
});

test('stripe errors become stripe exceptions', function () {
    FakeStripe::fake([
        FakeStripe::url('customers/cus_declined*') => FakeStripe::error('card_error', 'Your card was declined.', 402, 'card_declined'),
        FakeStripe::url('customers/cus_missing*') => FakeStripe::error('invalid_request_error', 'No such customer: cus_missing', 404, 'resource_missing'),
    ]);

    expect(fn() => Cashier::stripe()->customers->retrieve('cus_declined'))->toThrow(CardException::class, 'Your card was declined.')
        ->and(fn() => Cashier::stripe()->customers->retrieve('cus_missing'))->toThrow(InvalidRequestException::class, 'No such customer: cus_missing');
});

test('a connection failure is retried, then reported as stripe being unreachable', function () {
    FakeStripe::fake([FakeStripe::url('customers/*') => Http::failedConnection()]);
    Stripe::setMaxNetworkRetries(2);

    try {
        Cashier::stripe()->customers->retrieve('cus_1');
        $this->fail('Expected Stripe to be unreachable.');
    } catch (ApiConnectionException $exception) {
        expect($exception->getMessage())->toStartWith('Could not reach Stripe');
    }

    expect(Http::recorded())->toHaveCount(3);
});

test('a conflict is retried and the retry keeps the idempotency key', function () {
    FakeStripe::fake([FakeStripe::url('customers') => Http::sequence()->push(['error' => ['type' => 'idempotency_error', 'message' => 'Conflict']], 409)->push(FakeStripe::customer('cus_3'))]);
    Stripe::setMaxNetworkRetries(2);

    Cashier::stripe()->customers->create(['name' => 'Monoven Ltd']);

    $keys = collect(Http::recorded())->map(fn(array $pair): string => $pair[0]->header('Idempotency-Key')[0] ?? '');

    expect($keys)->toHaveCount(2)
        ->and($keys[0])->not->toBe('')
        ->and($keys[1])->toBe($keys[0]);
});

test('stripe can say not to retry', function () {
    FakeStripe::fake([FakeStripe::url('customers') => Http::response(['error' => ['type' => 'api_error', 'message' => 'Not this time.']], 500, ['Stripe-Should-Retry' => 'false'])]);
    Stripe::setMaxNetworkRetries(2);

    expect(fn() => Cashier::stripe()->customers->create(['name' => 'Monoven Ltd']))->toThrow(ApiErrorException::class);

    expect(Http::recorded())->toHaveCount(1);
});

test('only plain v1 requests are sent', function () {
    FakeStripe::fake();
    $client = app(StripeHttpClient::class);

    expect(fn() => $client->request('post', 'https://api.stripe.com/v1/files', [], [], true))->toThrow(UnexpectedValueException::class)
        ->and(fn() => $client->request('get', 'https://api.stripe.com/v2/core/accounts', [], [], false, 'v2'))->toThrow(UnexpectedValueException::class);

    Http::assertNothingSent();
});

test('the api base can point at a stand-in outside production', function () {
    FakeStripe::fake(['127.0.0.1:8126/v1/customers/*' => FakeStripe::customer('cus_4')]);
    config(['coresmith.billing.stripe_api_base' => 'http://127.0.0.1:8126/']);

    (new BillingServiceProvider(app()))->boot();

    expect(Cashier::stripe()->customers->retrieve('cus_4')->id)->toBe('cus_4');

    Http::assertSent(fn(Request $request): bool => str_starts_with($request->url(), 'http://127.0.0.1:8126/v1/customers/cus_4'));
});

test('production never points stripe anywhere else', function () {
    config(['coresmith.billing.stripe_api_base' => 'http://127.0.0.1:8126']);
    app()->detectEnvironment(fn(): string => 'production');

    try {
        (new BillingServiceProvider(app()))->boot();
    } finally {
        app()->detectEnvironment(fn(): string => 'testing');
    }

    expect(Cashier::$apiBaseUrl)->toBe(BaseStripeClient::DEFAULT_API_BASE);
});
