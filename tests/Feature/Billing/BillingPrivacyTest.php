<?php

declare(strict_types=1);

use App\Enums\Plan;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Arr;
use Tests\Support\CloudBilling;

function billingPrivacyTeam(): Team
{
    $team = Team::factory()->subscribed(Plan::Pro)->owned()->create();

    $team->forceFill([
        'stripe_id' => 'cus_private_customer',
        'pm_type' => 'visa',
        'pm_last_four' => '4242',
        'pm_expires' => '08/28',
        'trial_ends_at' => now()->addDays(9),
        'trial_reminded_at' => now(),
        'pending_checkout_id' => 'cs_private_checkout',
        'free_plan' => Plan::Business,
        'free_until' => now()->addDays(30),
    ])->save();

    $team->subscription('default')->forceFill(['stripe_id' => 'sub_private_subscription', 'unit_amount' => 1900, 'currency' => 'usd'])->save();

    return $team->fresh();
}

function billingPrivacyProps(User $user, string $route): array
{
    $props = null;

    test()->actingAs($user)->get(route($route))->assertOk()->assertInertia(function ($page) use (&$props) {
        $props = $page->toArray()['props'];
    });

    return $props;
}

/**
 * @return list<string>
 */
function billingPrivacyKeys(array $props): array
{
    $keys = [];

    foreach ($props as $key => $value) {
        $keys[] = (string) $key;

        if (is_array($value)) {
            $keys = [...$keys, ...billingPrivacyKeys($value)];
        }
    }

    return $keys;
}

function billingPrivacyExpectNothingPrivate(array $props): void
{
    $keys = billingPrivacyKeys($props);
    $values = array_filter(Arr::flatten($props), 'is_string');

    foreach (['stripe_id', 'pm_type', 'pm_last_four', 'pm_expires', 'trial_ends_at', 'trial_reminded_at', 'pending_checkout_id', 'free_plan', 'free_until', 'subscriptions', 'unit_amount'] as $column) {
        expect($keys)->not->toContain($column);
    }

    foreach (['cus_private_customer', 'cs_private_checkout', 'sub_private_subscription'] as $id) {
        expect((string) json_encode($props))->not->toContain($id);
    }

    expect(array_intersect($values, ['4242', '08/28', 'visa']))->toBe([]);
}

beforeEach(function () {
    CloudBilling::enable();
});

test('a deployer sees no card, stripe id or trial date on any page', function (string $route) {
    $team = billingPrivacyTeam();

    billingPrivacyExpectNothingPrivate(billingPrivacyProps(CloudBilling::member($team, TeamRole::Member), $route));
})->with(['dashboard', 'settings.general', 'teams.index', 'settings.billing']);

test('an admin sees no card, stripe id or trial date on any page', function (string $route) {
    $team = billingPrivacyTeam();

    billingPrivacyExpectNothingPrivate(billingPrivacyProps(CloudBilling::member($team, TeamRole::Admin), $route));
})->with(['dashboard', 'settings.general', 'teams.index', 'settings.billing']);

test('an owner carries no raw billing columns on any page', function (string $route) {
    $team = billingPrivacyTeam();

    billingPrivacyExpectNothingPrivate(billingPrivacyProps(CloudBilling::owner($team), $route));
})->with(['dashboard', 'settings.general', 'teams.index']);

test('an owner sees the card only on billing, as a sentence', function () {
    $team = billingPrivacyTeam();
    $props = billingPrivacyProps(CloudBilling::owner($team), 'settings.billing');

    expect($props['payment'])->toBe(['label' => 'Visa •••• 4242', 'expires' => '08/28'])
        ->and(billingPrivacyKeys($props))->not->toContain('stripe_id')
        ->and(billingPrivacyKeys($props))->not->toContain('pm_last_four')
        ->and((string) json_encode($props))->not->toContain('cus_private_customer')
        ->and((string) json_encode($props))->not->toContain('sub_private_subscription');
});

test('the shared user says who is signed in and nothing else', function () {
    $team = billingPrivacyTeam();
    $owner = CloudBilling::owner($team);

    expect(billingPrivacyProps($owner, 'dashboard')['auth']['user'])->toBe([
        'id' => $owner->id,
        'name' => $owner->name,
        'email' => $owner->email,
        'email_verified_at' => $owner->email_verified_at?->toISOString(),
    ]);
});

test('nobody signed in shares no user', function () {
    $this->get(route('login'))->assertOk()->assertInertia(fn($page) => $page->where('auth.user', null));
});

test('an unconfirmed address is shared as unconfirmed', function () {
    $team = billingPrivacyTeam();
    $owner = CloudBilling::owner($team);

    $owner->forceFill(['email_verified_at' => null])->save();

    $this->actingAs($owner->fresh())->get(route('profile.edit'))->assertOk()
        ->assertInertia(fn($page) => $page->where('auth.user.email_verified_at', null));
});

test('a team turned into an array holds nothing about billing', function () {
    $team = billingPrivacyTeam();

    $team->load(['subscriptions', 'billingInvoices', 'backupDestination']);

    expect($team->toArray())->not->toHaveKeys(['stripe_id', 'pm_type', 'pm_last_four', 'pm_expires', 'trial_ends_at', 'trial_reminded_at', 'pending_checkout_id', 'free_plan', 'free_until', 'subscriptions', 'billing_invoices', 'backup_destination'])
        ->and($team->toArray())->toHaveKeys(['id', 'name', 'slug', 'owner_id']);
});
