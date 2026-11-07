<?php

declare(strict_types=1);

use App\Jobs\SyncStripeCustomerEmail;
use App\Models\Subscription;
use App\Models\Team;
use App\Models\TeamInvoice;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Cashier\Cashier;
use Stripe\Exception\ApiErrorException;
use Tests\Support\CloudBilling;
use Tests\Support\FakeStripe;

test('cashier bills teams, including soft-deleted ones', function () {
    $team = Team::factory()->create();
    $team->forceFill(['stripe_id' => 'cus_gone'])->save();
    $team->delete();

    expect(Cashier::$customerModel)->toBe(Team::class)
        ->and(Cashier::$subscriptionModel)->toBe(Subscription::class)
        ->and(Cashier::findBillable('cus_gone')?->is($team))->toBeTrue();
});

test("a team's subscriptions hang off the team", function () {
    $team = Team::factory()->create();

    $subscription = Subscription::query()->create([
        'team_id' => $team->id,
        'type' => 'default',
        'stripe_id' => 'sub_1',
        'stripe_status' => 'active',
        'stripe_price' => 'price_test_pro',
        'quantity' => 1,
    ]);

    expect($team->subscription()?->is($subscription))->toBeTrue()
        ->and($subscription->owner->is($team))->toBeTrue();
});

test("stripe knows the team by its name and its owner's email", function () {
    $owner = User::factory()->create(['email' => 'owner@monoven.test']);
    $team = Team::factory()->create(['name' => 'Monoven', 'owner_id' => $owner->id]);

    expect($team->stripeName())->toBe('Monoven')
        ->and($team->stripeEmail())->toBe('owner@monoven.test');
});

test('the card on file is remembered with its expiry', function () {
    FakeStripe::fake([FakeStripe::url('customers/cus_1*') => FakeStripe::customer('cus_1', ['invoice_settings' => ['default_payment_method' => [...FakeStripe::paymentMethod('pm_1'), 'customer' => 'cus_1']]])]);
    $team = Team::factory()->create();
    $team->forceFill(['stripe_id' => 'cus_1'])->save();

    $team->updateDefaultPaymentMethodFromStripe();

    expect($team->fresh())
        ->pm_type->toBe('visa')
        ->pm_last_four->toBe('4242')
        ->pm_expires->toBe('08/28');
});

test('a payment method that is not a card keeps no expiry', function () {
    $method = [...FakeStripe::paymentMethod('pm_2'), 'customer' => 'cus_2', 'type' => 'us_bank_account', 'us_bank_account' => ['last4' => '6789', 'bank_name' => 'Stripe Bank']];
    unset($method['card']);
    FakeStripe::fake([FakeStripe::url('customers/cus_2*') => FakeStripe::customer('cus_2', ['invoice_settings' => ['default_payment_method' => $method]])]);
    $team = Team::factory()->create();
    $team->forceFill(['stripe_id' => 'cus_2', 'pm_expires' => '01/27'])->save();

    $team->updateDefaultPaymentMethodFromStripe();

    expect($team->fresh()->pm_expires)->toBeNull();
});

test("the team's invoices are local records, not stripe calls", function () {
    $team = Team::factory()->create();

    expect($team->billingInvoices())->toBeInstanceOf(HasMany::class)
        ->and($team->billingInvoices()->getRelated())->toBeInstanceOf(TeamInvoice::class);
});

test("a changed owner's email reaches stripe once it is confirmed, and nothing else does", function () {
    CloudBilling::enable();
    $owner = User::factory()->create(['email' => 'old@monoven.test']);
    $team = Team::factory()->create(['owner_id' => $owner->id, 'stripe_id' => 'cus_1']);
    $other = Team::factory()->create(['owner_id' => $owner->id, 'stripe_id' => 'cus_2']);
    Team::factory()->create(['owner_id' => $owner->id, 'stripe_id' => 'cus_3'])->delete();
    Team::factory()->create(['owner_id' => $owner->id]);

    FakeStripe::fake([FakeStripe::url('customers/*') => Http::response(FakeStripe::customer('cus_1'))]);

    $this->actingAs($owner)->patch(route('profile.update'), ['name' => $owner->name, 'email' => 'new@monoven.test'])->assertSessionHasNoErrors();

    Http::assertNothingSent();

    $owner->fresh()->markEmailAsVerified();

    $sent = collect(Http::recorded())->map(fn(array $pair): Request => $pair[0]);

    expect($sent->map(fn(Request $request): string => $request->method() . ' ' . parse_url($request->url(), PHP_URL_PATH))->sort()->values()->all())->toBe(['POST /v1/customers/cus_1', 'POST /v1/customers/cus_2', 'POST /v1/customers/cus_3'])
        ->and($sent->map(fn(Request $request): array => $request->data())->unique()->values()->all())->toBe([['email' => 'new@monoven.test']])
        ->and($team->fresh()->stripeEmail())->toBe('new@monoven.test')
        ->and($other->fresh()->stripeEmail())->toBe('new@monoven.test');
});

test('an address that stays confirmed reaches stripe at once', function () {
    CloudBilling::enable();
    $owner = User::factory()->create(['email' => 'old@monoven.test']);
    Team::factory()->create(['owner_id' => $owner->id, 'stripe_id' => 'cus_1']);

    FakeStripe::fake([FakeStripe::url('customers/*') => Http::response(FakeStripe::customer('cus_1'))]);

    $owner->forceFill(['email' => 'new@monoven.test'])->save();

    Http::assertSentCount(1);
    Http::assertSent(fn(Request $request): bool => $request['email'] === 'new@monoven.test');
});

test('a job already waiting never sends an address that is no longer confirmed', function () {
    CloudBilling::enable();
    $owner = User::factory()->unverified()->create();
    $team = Team::factory()->create(['owner_id' => $owner->id, 'stripe_id' => 'cus_1']);

    FakeStripe::fake();

    (new SyncStripeCustomerEmail($team))->handle();

    Http::assertNothingSent();
});

test('stripe hears nothing when no receipt address changed', function () {
    CloudBilling::enable();
    $owner = User::factory()->create();
    $member = User::factory()->create();
    Team::factory()->create(['owner_id' => $owner->id, 'stripe_id' => 'cus_1'])->members()->attach($member, ['role' => 'member']);

    FakeStripe::fake();

    $owner->forceFill(['name' => 'Someone else'])->save();
    $member->forceFill(['email' => 'member@monoven.test'])->save();

    Http::assertNothingSent();
});

test('a self-hosted panel never tells stripe about an email', function () {
    config(['coresmith.edition' => 'self-hosted']);
    $owner = User::factory()->create();
    Team::factory()->create(['owner_id' => $owner->id, 'stripe_id' => 'cus_1']);

    FakeStripe::fake();

    $owner->forceFill(['email' => 'new@monoven.test'])->save();

    Http::assertNothingSent();
});

test('stripe being down does not stop an email changing, and the job retries', function () {
    CloudBilling::enable();
    $owner = User::factory()->create(['email' => 'old@monoven.test']);
    $team = Team::factory()->create(['owner_id' => $owner->id, 'stripe_id' => 'cus_1']);
    Team::factory()->create(['owner_id' => $owner->id]);

    Queue::fake();
    FakeStripe::fake([FakeStripe::url('customers/*') => FakeStripe::error('api_error', "Something went wrong on Stripe's end.", 500)]);

    $this->actingAs($owner)->patch(route('profile.update'), ['name' => $owner->name, 'email' => 'new@monoven.test'])->assertSessionHasNoErrors();
    $owner->fresh()->markEmailAsVerified();

    expect($owner->fresh()->email)->toBe('new@monoven.test');

    Http::assertNothingSent();

    $pushed = Queue::pushed(SyncStripeCustomerEmail::class);
    $job = $pushed->last();

    expect($pushed)->toHaveCount(2)
        ->and($job->team->is($team))->toBeTrue()
        ->and($job->queue)->toBe('default')
        ->and($job->tries)->toBeGreaterThan(1)
        ->and(fn() => $job->handle())->toThrow(ApiErrorException::class);
});

test('a team that lost its stripe customer before the job ran is left alone', function () {
    CloudBilling::enable();
    $team = Team::factory()->owned()->create(['stripe_id' => null]);

    FakeStripe::fake();

    (new SyncStripeCustomerEmail($team))->handle();

    Http::assertNothingSent();
});
