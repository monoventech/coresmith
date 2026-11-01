<?php

declare(strict_types=1);

use App\Models\Subscription;
use App\Models\Team;
use App\Models\TeamInvoice;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Cashier\Cashier;
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
