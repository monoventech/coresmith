<?php

declare(strict_types=1);

use App\Enums\Plan;
use App\Enums\ServerStatus;
use App\Enums\TeamRole;
use App\Models\Server;
use App\Models\Subscription;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\CloudBilling;
use Tests\Support\FakeStripe;

function planChangeOwner(Team $team): User
{
    return User::query()->findOrFail($team->owner_id);
}

function planChangeSent(string $method, string $path): Request
{
    $pair = collect(Http::recorded())->first(fn(array $pair): bool => $pair[0]->method() === $method && parse_url($pair[0]->url(), PHP_URL_PATH) === '/v1/' . $path);

    expect($pair)->not->toBeNull();

    return $pair[0];
}

/**
 * @return list<string>
 */
function planChangeCalls(): array
{
    return collect(Http::recorded())->map(fn(array $pair): string => $pair[0]->method() . ' ' . parse_url($pair[0]->url(), PHP_URL_PATH))->values()->all();
}

function planChangeActivity(string $event): ?Activity
{
    return Activity::query()->where('description', $event)->latest('id')->first();
}

function planChangeSubscription(Team $team, string $price, array $overrides = []): array
{
    $subscription = $team->subscription('default');

    return FakeStripe::subscription((string) $subscription->stripe_id, (string) $team->stripe_id, $price, 'active', array_replace_recursive(
        ['items' => ['data' => [['id' => $subscription->items()->value('stripe_id')]]]],
        $overrides,
    ));
}

function planChangeCheckout(): void
{
    FakeStripe::fake([
        FakeStripe::url('customers') => Http::response(FakeStripe::customer('cus_acme')),
        FakeStripe::url('checkout/sessions') => Http::response(FakeStripe::checkoutSession('cs_acme', 'https://checkout.stripe.com/c/pay/cs_acme')),
    ]);
}

beforeEach(function () {
    CloudBilling::enable();
    $this->travelTo(CarbonImmutable::parse('2026-10-18 12:00:00'));
});

test('a trial team chooses pro at checkout and keeps its remaining days', function () {
    $team = Team::factory()->onTrial(9)->owned()->create(['name' => 'Acme Labs']);
    $owner = planChangeOwner($team);
    planChangeCheckout();

    $this->actingAs($owner)->put(route('settings.billing.plan.update'), ['plan' => 'pro'])
        ->assertRedirect('https://checkout.stripe.com/c/pay/cs_acme');

    $customer = planChangeSent('POST', 'customers');
    $session = planChangeSent('POST', 'checkout/sessions');
    $started = planChangeActivity('checkout_started');

    expect($customer['name'])->toBe('Acme Labs')
        ->and($customer['email'])->toBe($owner->email)
        ->and($session['customer'])->toBe('cus_acme')
        ->and($session['line_items'][0]['price'])->toBe('price_test_pro')
        ->and($session['mode'])->toBe('subscription')
        ->and((int) $session['subscription_data']['trial_end'])->toBe($team->fresh()->trial_ends_at->getTimestamp())
        ->and($session['subscription_data']['metadata']['team_id'])->toBe($team->getKey())
        ->and($session['success_url'])->toEndWith('settings/billing?checkout=success')
        ->and($session['cancel_url'])->toEndWith('settings/billing?checkout=cancelled')
        ->and($session['client_reference_id'])->toBe($team->getKey())
        ->and($team->fresh()->pending_checkout_id)->toBe('cs_acme')
        ->and($started?->properties->all())->toBe(['plan' => 'pro'])
        ->and($started?->causer_id)->toBe($owner->getKey())
        ->and($started?->subject_id)->toBe($team->getKey());
});

test('an inertia visit leaves for checkout with a full page load', function () {
    $team = Team::factory()->onTrial(9)->owned()->create();
    planChangeCheckout();

    $this->actingAs(planChangeOwner($team))
        ->put(route('settings.billing.plan.update'), ['plan' => 'pro'], ['X-Inertia' => 'true'])
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location', 'https://checkout.stripe.com/c/pay/cs_acme');
});

test('a team whose trial ended pays at checkout', function () {
    $team = Team::factory()->trialEnded(2)->owned()->create();
    planChangeCheckout();

    $this->actingAs(planChangeOwner($team))->put(route('settings.billing.plan.update'), ['plan' => 'starter'])
        ->assertRedirect('https://checkout.stripe.com/c/pay/cs_acme');

    $session = planChangeSent('POST', 'checkout/sessions');

    expect($session['line_items'][0]['price'])->toBe('price_test_starter')
        ->and($session['subscription_data'])->not->toHaveKey('trial_end');
});

test('a second checkout closes the first one', function () {
    $team = Team::factory()->onTrial(9)->owned()->create();
    $team->forceFill(['stripe_id' => 'cus_acme', 'pending_checkout_id' => 'cs_old'])->save();

    FakeStripe::fake([
        FakeStripe::url('customers/cus_acme*') => Http::response(FakeStripe::customer('cus_acme')),
        FakeStripe::url('checkout/sessions/cs_old/expire') => Http::response(FakeStripe::checkoutSession('cs_old', 'https://checkout.stripe.com/c/pay/cs_old', ['status' => 'expired'])),
        FakeStripe::url('checkout/sessions') => Http::response(FakeStripe::checkoutSession('cs_new', 'https://checkout.stripe.com/c/pay/cs_new')),
    ]);

    $this->actingAs(planChangeOwner($team))->put(route('settings.billing.plan.update'), ['plan' => 'pro'])
        ->assertRedirect('https://checkout.stripe.com/c/pay/cs_new');

    $calls = planChangeCalls();

    expect(array_search('POST /v1/checkout/sessions/cs_old/expire', $calls, true))->toBeInt()
        ->toBeLessThan(array_search('POST /v1/checkout/sessions', $calls, true))
        ->and($team->fresh()->pending_checkout_id)->toBe('cs_new');
});

test('a checkout that already went through is not started again', function () {
    $team = Team::factory()->onTrial(9)->owned()->create();
    $team->forceFill(['stripe_id' => 'cus_acme', 'pending_checkout_id' => 'cs_old'])->save();

    FakeStripe::fake([
        FakeStripe::url('checkout/sessions/cs_old/expire') => FakeStripe::error('invalid_request_error', 'Only Checkout Sessions with a status in [open] can be expired.', 400),
        FakeStripe::url('checkout/sessions/cs_old*') => Http::response(FakeStripe::checkoutSession('cs_old', 'https://checkout.stripe.com/c/pay/cs_old', ['status' => 'complete'])),
        FakeStripe::url('*') => Http::response(FakeStripe::checkoutSession('cs_new', 'https://checkout.stripe.com/c/pay/cs_new')),
    ]);

    $this->actingAs(planChangeOwner($team))->from(route('settings.billing'))->put(route('settings.billing.plan.update'), ['plan' => 'pro'])
        ->assertRedirect(route('settings.billing'))
        ->assertSessionHasErrors(['plan' => 'A checkout for this team has already gone through. Its plan appears here in a moment.']);

    expect(planChangeCalls())->toBe(['POST /v1/checkout/sessions/cs_old/expire', 'GET /v1/checkout/sessions/cs_old'])
        ->and($team->fresh()->pending_checkout_id)->toBe('cs_old');
});

test('a closed checkout that never went through is replaced', function () {
    $team = Team::factory()->onTrial(9)->owned()->create();
    $team->forceFill(['stripe_id' => 'cus_acme', 'pending_checkout_id' => 'cs_old'])->save();

    FakeStripe::fake([
        FakeStripe::url('customers/cus_acme*') => Http::response(FakeStripe::customer('cus_acme')),
        FakeStripe::url('checkout/sessions/cs_old/expire') => FakeStripe::error('invalid_request_error', 'Only Checkout Sessions with a status in [open] can be expired.', 400),
        FakeStripe::url('checkout/sessions/cs_old*') => Http::response(FakeStripe::checkoutSession('cs_old', 'https://checkout.stripe.com/c/pay/cs_old', ['status' => 'expired'])),
        FakeStripe::url('checkout/sessions') => Http::response(FakeStripe::checkoutSession('cs_new', 'https://checkout.stripe.com/c/pay/cs_new')),
    ]);

    $this->actingAs(planChangeOwner($team))->put(route('settings.billing.plan.update'), ['plan' => 'pro'])
        ->assertRedirect('https://checkout.stripe.com/c/pay/cs_new');

    expect($team->fresh()->pending_checkout_id)->toBe('cs_new');
});

test('a subscribed team swaps to business at once, prorated', function () {
    $team = Team::factory()->subscribed(Plan::Pro)->owned()->create();
    $subscription = $team->subscription('default');
    $item = $subscription->items()->value('stripe_id');
    $owner = planChangeOwner($team);

    FakeStripe::fake([
        FakeStripe::url("subscriptions/{$subscription->stripe_id}*") => fn(Request $request) => Http::response(
            $request->method() === 'GET' ? planChangeSubscription($team, 'price_test_pro') : FakeStripe::subscription($subscription->stripe_id, $team->stripe_id, 'price_test_business'),
        ),
    ]);

    $this->actingAs($owner)->from(route('settings.billing'))->put(route('settings.billing.plan.update'), ['plan' => 'business'])
        ->assertRedirect(route('settings.billing'))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', "Saved. You're on Business now. The difference is prorated on your next invoice.");

    $update = planChangeSent('POST', "subscriptions/{$subscription->stripe_id}");
    $changed = planChangeActivity('plan_changed');

    expect($update['items'][0]['price'])->toBe('price_test_business')
        ->and($update['items'][1]['id'])->toBe($item)
        ->and($update['items'][1]['deleted'])->toBe('true')
        ->and($update['proration_behavior'])->toBe('create_prorations')
        ->and($subscription->fresh()->stripe_price)->toBe('price_test_business')
        ->and($changed?->properties->all())->toBeInAnyKeyOrder(['from' => 'pro', 'to' => 'business'])
        ->and($changed?->causer_id)->toBe($owner->getKey());
});

test('a changed plan shows the configured price until stripe says what it costs', function () {
    $team = Team::factory()->subscribed(Plan::Pro)->owned()->create();
    $subscription = $team->subscription('default');
    $subscription->forceFill(['unit_amount' => 1500, 'currency' => 'usd'])->save();

    FakeStripe::fake([
        FakeStripe::url("subscriptions/{$subscription->stripe_id}*") => fn(Request $request) => Http::response(
            $request->method() === 'GET' ? planChangeSubscription($team, 'price_test_pro') : FakeStripe::subscription($subscription->stripe_id, $team->stripe_id, 'price_test_business'),
        ),
    ]);

    $this->actingAs(planChangeOwner($team))->from(route('settings.billing'))->put(route('settings.billing.plan.update'), ['plan' => 'business'])->assertSessionHasNoErrors();

    expect($subscription->fresh()->unit_amount)->toBeNull()
        ->and($subscription->fresh()->currency)->toBeNull();

    $this->get(route('settings.billing'))->assertInertia(fn($page) => $page->where('plan.amount', '$39'));
});

test('a downgrade over the limits is refused before stripe hears of it', function () {
    $team = Team::factory()->subscribed(Plan::Pro)->owned()->create();
    Server::factory()->for($team)->count(5)->create(['status' => ServerStatus::Active]);
    FakeStripe::fake();

    $this->actingAs(planChangeOwner($team))->put(route('settings.billing.plan.update'), ['plan' => 'starter'])
        ->assertSessionHasErrors(['plan' => 'Starter includes 3 servers and this team has 5. Detach 2 first.']);

    Http::assertNothingSent();
});

test('plan changes wait for a failed payment and an ending plan', function () {
    $failed = 'The last payment failed. Update the payment method first, then change the plan.';
    $ending = Team::factory()->owned()->create(['stripe_id' => 'cus_ending']);
    Subscription::factory()->for($ending, 'owner')->plan(Plan::Pro)->ending(now()->addDays(10))->create();
    FakeStripe::fake();

    foreach (['past_due', 'unpaid', 'incomplete'] as $status) {
        $team = Team::factory()->subscribed(Plan::Pro, $status)->owned()->create();

        $this->actingAs(planChangeOwner($team))->put(route('settings.billing.plan.update'), ['plan' => 'business'])
            ->assertSessionHasErrors(['plan' => $failed]);
    }

    $this->actingAs(planChangeOwner($ending))->put(route('settings.billing.plan.update'), ['plan' => 'business'])
        ->assertSessionHasErrors(['plan' => 'This plan is ending. Keep it first, then change it.']);

    Http::assertNothingSent();
});

test('the current plan cannot be chosen again', function () {
    $team = Team::factory()->subscribed(Plan::Pro)->owned()->create();
    FakeStripe::fake();

    $this->actingAs(planChangeOwner($team))->put(route('settings.billing.plan.update'), ['plan' => 'pro'])
        ->assertSessionHasErrors(['plan' => 'The team is already on Pro. Choose a different plan.']);

    Http::assertNothingSent();
});

test('a plan without a price is not offered', function () {
    config(['coresmith.billing.plans.business.prices' => null]);
    $team = Team::factory()->onTrial()->owned()->create();
    FakeStripe::fake();

    $this->actingAs(planChangeOwner($team))->put(route('settings.billing.plan.update'), ['plan' => 'business'])
        ->assertSessionHasErrors(['plan' => 'Business is not available on this panel yet. Choose another plan.']);

    Http::assertNothingSent();
});

test('only the three plans can be chosen', function (mixed $plan) {
    $team = Team::factory()->onTrial()->owned()->create();
    FakeStripe::fake();

    $this->actingAs(planChangeOwner($team))->put(route('settings.billing.plan.update'), ['plan' => $plan])
        ->assertSessionHasErrors(['plan' => 'Choose Starter, Pro or Business.']);

    Http::assertNothingSent();
})->with(['missing' => [null], 'trial' => ['trial'], 'self-hosted' => ['self-hosted'], 'unknown' => ['enterprise']]);

test('stripe failing leaves the plan as it was', function () {
    $team = Team::factory()->subscribed(Plan::Pro)->owned()->create();
    $subscription = $team->subscription('default');
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged) {
        $logged[] = $event;
    });

    FakeStripe::fake([
        FakeStripe::url("subscriptions/{$subscription->stripe_id}*") => fn(Request $request) => $request->method() === 'GET'
            ? Http::response(planChangeSubscription($team, 'price_test_pro'))
            : FakeStripe::error('api_error', "Something went wrong on Stripe's end.", 500),
    ]);

    $this->actingAs(planChangeOwner($team))->put(route('settings.billing.plan.update'), ['plan' => 'business'])
        ->assertSessionHasErrors(['plan' => 'Stripe could not make that change, so nothing changed. Try again in a minute.']);

    expect($subscription->fresh()->stripe_price)->toBe('price_test_pro')
        ->and(planChangeActivity('plan_changed'))->toBeNull();

    $warning = collect($logged)->first(fn(MessageLogged $event): bool => $event->level === 'warning' && ($event->context['stripe'] ?? null) === "Something went wrong on Stripe's end.");

    expect($warning?->context['team'])->toBe($team->getKey())
        ->and(json_encode($warning?->context))->not->toContain(FakeStripe::SECRET);
});

test('ending a plan keeps it until the period ends', function () {
    $team = Team::factory()->subscribed(Plan::Pro)->owned()->create();
    $subscription = $team->subscription('default');
    $item = $subscription->items()->value('stripe_id');

    FakeStripe::fake([
        FakeStripe::url("subscriptions/{$subscription->stripe_id}*") => Http::response(planChangeSubscription($team, 'price_test_pro', ['cancel_at_period_end' => true])),
        FakeStripe::url("subscription_items/{$item}*") => Http::response([
            'id' => $item,
            'object' => 'subscription_item',
            'current_period_end' => CarbonImmutable::parse('2026-11-18 09:00:00')->getTimestamp(),
        ]),
    ]);

    $this->actingAs(planChangeOwner($team))->from(route('settings.billing'))->delete(route('settings.billing.plan.destroy'))
        ->assertRedirect(route('settings.billing'))
        ->assertSessionHas('success', 'Saved. The plan ends on Nov 18. After that this team is read-only and its servers keep running.');

    expect(planChangeSent('POST', "subscriptions/{$subscription->stripe_id}")['cancel_at_period_end'])->toBe('true')
        ->and($subscription->fresh()->ends_at?->toDateTimeString())->toBe('2026-11-18 09:00:00')
        ->and(planChangeActivity('plan_cancelled')?->causer_id)->toBe($team->owner_id);
});

test('only a running plan can be ended', function () {
    $trial = Team::factory()->onTrial()->owned()->create();
    $ending = Team::factory()->owned()->create(['stripe_id' => 'cus_ending']);
    Subscription::factory()->for($ending, 'owner')->plan(Plan::Business)->ending(CarbonImmutable::parse('2026-10-28'))->create();
    FakeStripe::fake();

    $this->actingAs(planChangeOwner($trial))->delete(route('settings.billing.plan.destroy'))
        ->assertSessionHasErrors(['plan' => 'There is no plan to end. The trial ends on its own.']);
    $this->actingAs(planChangeOwner($ending))->delete(route('settings.billing.plan.destroy'))
        ->assertSessionHasErrors(['plan' => 'This plan already ends on Oct 28. Keep it to stay on Business.']);

    Http::assertNothingSent();
});

test('keeping an ending plan', function () {
    $team = Team::factory()->owned()->create(['stripe_id' => 'cus_keep']);
    $subscription = Subscription::factory()->for($team, 'owner')->plan(Plan::Pro)->ending(now()->addDays(10))->create();

    FakeStripe::fake([
        FakeStripe::url("subscriptions/{$subscription->stripe_id}*") => Http::response(planChangeSubscription($team, 'price_test_pro')),
    ]);

    $this->actingAs(planChangeOwner($team))->from(route('settings.billing'))->post(route('settings.billing.plan.resume'))
        ->assertRedirect(route('settings.billing'))
        ->assertSessionHas('success', 'Saved. The plan continues.');

    expect(planChangeSent('POST', "subscriptions/{$subscription->stripe_id}")['cancel_at_period_end'])->toBe('false')
        ->and($subscription->fresh()->ends_at)->toBeNull()
        ->and(planChangeActivity('plan_resumed')?->causer_id)->toBe($team->owner_id);
});

test('a plan that is not ending has nothing to keep', function () {
    $team = Team::factory()->subscribed(Plan::Pro)->owned()->create();
    FakeStripe::fake();

    $this->actingAs(planChangeOwner($team))->post(route('settings.billing.plan.resume'))
        ->assertSessionHasErrors(['plan' => 'This plan is not ending, so there is nothing to keep.']);

    Http::assertNothingSent();
});

test("the card is changed in stripe's portal", function () {
    $team = Team::factory()->subscribed(Plan::Pro)->owned()->create();
    FakeStripe::fake([FakeStripe::url('billing_portal/sessions') => Http::response(FakeStripe::portalSession('https://billing.stripe.com/p/session/test_portal'))]);

    $this->actingAs(planChangeOwner($team))->post(route('settings.billing.portal'))
        ->assertRedirect('https://billing.stripe.com/p/session/test_portal');

    $portal = planChangeSent('POST', 'billing_portal/sessions');

    expect($portal['customer'])->toBe($team->stripe_id)
        ->and($portal['return_url'])->toBe(route('settings.billing'));
});

test('without a customer there is no card to change', function () {
    $team = Team::factory()->onTrial()->owned()->create();
    FakeStripe::fake();

    $this->actingAs(planChangeOwner($team))->post(route('settings.billing.portal'))
        ->assertSessionHasErrors(['plan' => 'There is no payment method to update yet. Choose a plan first.']);

    Http::assertNothingSent();
});

test('only owners change billing', function () {
    $team = Team::factory()->subscribed(Plan::Pro)->owned()->create();
    $admin = CloudBilling::member($team, TeamRole::Admin);
    $admin->forceFill(['current_team_id' => $team->getKey()])->save();
    FakeStripe::fake();

    $this->actingAs($admin->fresh());

    $this->put(route('settings.billing.plan.update'), ['plan' => 'business'])->assertForbidden();
    $this->delete(route('settings.billing.plan.destroy'))->assertForbidden();
    $this->post(route('settings.billing.plan.resume'))->assertForbidden();
    $this->post(route('settings.billing.portal'))->assertForbidden();

    Http::assertNothingSent();
});

test('a lapsed team can still choose a plan', function () {
    $team = Team::factory()->trialEnded()->owned()->create();
    planChangeCheckout();

    $this->actingAs(planChangeOwner($team))->put(route('settings.billing.plan.update'), ['plan' => 'pro'])
        ->assertRedirect('https://checkout.stripe.com/c/pay/cs_acme');
});

test('self-hosted has no billing routes', function () {
    config(['coresmith.edition' => 'self-hosted']);
    $team = Team::factory()->owned()->create();
    FakeStripe::fake();

    $this->actingAs(planChangeOwner($team));

    $this->put(route('settings.billing.plan.update'), ['plan' => 'pro'])->assertNotFound();
    $this->delete(route('settings.billing.plan.destroy'))->assertNotFound();
    $this->post(route('settings.billing.plan.resume'))->assertNotFound();
    $this->post(route('settings.billing.portal'))->assertNotFound();

    Http::assertNothingSent();
});

test('returning from checkout', function () {
    $waiting = Team::factory()->onTrial()->owned()->create();
    $paid = Team::factory()->subscribed(Plan::Pro)->owned()->create();
    $cardDue = Team::factory()->subscribed(Plan::Pro)->owned()->create();
    $cardDue->forceFill(['pending_checkout_id' => 'cs_card_due'])->save();
    $timeout = 'Stripe has not confirmed the plan yet. Refresh in a minute.';
    $cardTimeout = "You're on Pro. Refresh in a minute to see the card.";
    $checkout = function (Team $team, array $query): mixed {
        $props = null;

        $this->actingAs(planChangeOwner($team))->get(route('settings.billing', $query))->assertOk()
            ->assertInertia(function ($page) use (&$props) {
                $props = $page->toArray()['props'];
            });

        return $props['checkout'];
    };

    expect($checkout($waiting, ['checkout' => 'success']))->toBe([
        'status' => 'success',
        'awaiting' => true,
        'message' => 'Checkout finished. The plan appears here as soon as Stripe confirms it.',
        'timeout_message' => $timeout,
    ])->and($checkout($paid, ['checkout' => 'success']))->toBe([
        'status' => 'success',
        'awaiting' => false,
        'message' => "You're on Pro.",
        'timeout_message' => $cardTimeout,
    ])->and($checkout($cardDue, ['checkout' => 'success']))->toBe([
        'status' => 'success',
        'awaiting' => true,
        'message' => "You're on Pro.",
        'timeout_message' => $cardTimeout,
    ])->and($checkout($waiting, ['checkout' => 'cancelled']))->toBe([
        'status' => 'cancelled',
        'awaiting' => false,
        'message' => 'Checkout was cancelled, so nothing was charged.',
        'timeout_message' => $timeout,
    ])->and($checkout($paid, []))->toBeNull()
        ->and($checkout($paid, ['checkout' => 'done']))->toBeNull()
        ->and($checkout($paid, ['checkout' => ['success']]))->toBeNull();
});
