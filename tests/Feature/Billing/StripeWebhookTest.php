<?php

declare(strict_types=1);

use App\Enums\BillingState;
use App\Enums\Plan;
use App\Enums\ReadOnlyReason;
use App\Enums\TeamRole;
use App\Exceptions\PlanRefusal;
use App\Http\Middleware\VerifyStripeSignature;
use App\Models\StripeEvent;
use App\Models\Subscription;
use App\Models\Team;
use App\Models\TeamInvoice;
use App\Models\User;
use App\Notifications\PaymentFailedNotification;
use App\Notifications\PlanEndedNotification;
use App\Notifications\PlanStartedNotification;
use App\Services\Billing\BillingStatus;
use App\Services\Billing\InvoiceMirror;
use App\Services\Billing\SubscriptionCheck;
use App\Services\Billing\TeamBilling;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\CloudBilling;
use Tests\Support\FakeStripe;

function stripeWebhook(array|string $event, string $secret = FakeStripe::WEBHOOK_SECRET, ?int $timestamp = null): TestResponse
{
    $json = is_string($event) ? $event : (string) json_encode($event);
    $server = collect(FakeStripe::signedHeaders($json, $timestamp ?? time(), $secret))
        ->mapWithKeys(fn(string $value, string $header): array => ['HTTP_' . strtoupper(str_replace('-', '_', $header)) => $value])
        ->all();

    return test()->call('POST', route('cashier.webhook'), [], [], [], $server, $json);
}

function stripeWebhookOwner(Team $team): User
{
    return User::query()->findOrFail($team->owner_id);
}

function stripeWebhookActivity(string $event): ?Activity
{
    return Activity::query()->where('description', $event)->latest('id')->first();
}

function stripeWebhookStatus(Team $team): BillingStatus
{
    return app(TeamBilling::class)->status($team->fresh());
}

function stripeWebhookSubscription(Team $team, string $status = 'active', array $overrides = []): array
{
    $subscription = $team->subscription('default');

    return FakeStripe::subscription((string) $subscription->stripe_id, (string) $team->stripe_id, (string) $subscription->stripe_price, $status, array_replace_recursive(
        ['items' => ['data' => [['id' => $subscription->items()->value('stripe_id')]]]],
        $overrides,
    ));
}

beforeEach(function () {
    CloudBilling::enable();
    config(['cashier.webhook.secret' => FakeStripe::WEBHOOK_SECRET]);
    $this->travelTo(CarbonImmutable::parse('2026-10-18 12:00:00'));
    FakeStripe::fake();
    Notification::fake();
});

test('self-hosted has no stripe webhook', function () {
    config(['coresmith.edition' => 'self-hosted']);

    stripeWebhook(FakeStripe::event('customer.updated', FakeStripe::customer('cus_1')))->assertNotFound();
});

test('a panel without a webhook secret refuses every event', function () {
    config(['cashier.webhook.secret' => null]);
    Team::factory()->onTrial()->owned()->create(['stripe_id' => 'cus_1']);
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged) {
        $logged[] = $event;
    });

    stripeWebhook(FakeStripe::event('customer.subscription.created', FakeStripe::subscription('sub_1', 'cus_1', 'price_test_pro')), secret: '')
        ->assertStatus(503)
        ->assertSeeText('Stripe webhooks are not configured here.');

    expect(Subscription::query()->count())->toBe(0)
        ->and(collect($logged)->contains(fn(MessageLogged $event): bool => $event->level === 'critical'
            && $event->message === 'A Stripe webhook arrived but STRIPE_WEBHOOK_SECRET is not set, so it was refused.'))->toBeTrue();
});

test('a forged or stale signature is refused', function () {
    Team::factory()->onTrial()->owned()->create(['stripe_id' => 'cus_1']);
    $event = FakeStripe::event('customer.subscription.created', FakeStripe::subscription('sub_1', 'cus_1', 'price_test_pro'));

    stripeWebhook($event, secret: 'whsec_somebody_else')->assertForbidden()->assertSeeText('Invalid signature.');
    stripeWebhook($event, timestamp: time() - 600)->assertForbidden();

    expect(Subscription::query()->count())->toBe(0);
});

test('a new subscription gives the team its plan', function () {
    $team = Team::factory()->onTrial()->owned()->create(['stripe_id' => 'cus_1']);
    $admin = CloudBilling::member($team, TeamRole::Admin);
    $deployer = CloudBilling::member($team, TeamRole::Member);
    $periodEnd = CarbonImmutable::parse('2026-11-18 09:00:00');

    stripeWebhook(FakeStripe::event('customer.subscription.created', FakeStripe::subscription('sub_1', 'cus_1', 'price_test_pro', 'active', [
        'items' => ['data' => [['id' => 'si_1', 'current_period_end' => $periodEnd->getTimestamp()]]],
    ])))->assertOk();

    $status = stripeWebhookStatus($team);
    $subscription = $team->fresh()->subscription('default');

    expect($status->state)->toBe(BillingState::Active)
        ->and($status->plan)->toBe(Plan::Pro)
        ->and($subscription->renews_at?->toDateTimeString())->toBe('2026-11-18 09:00:00')
        ->and($subscription->synced_at?->toDateTimeString())->toBe('2026-10-18 12:00:00')
        ->and($team->fresh()->trial_ends_at)->toBeNull()
        ->and(stripeWebhookActivity('plan_started')?->properties->all())->toBe(['plan' => 'pro']);

    Notification::assertSentTo(stripeWebhookOwner($team), PlanStartedNotification::class);
    Notification::assertNotSentTo([$admin, $deployer], PlanStartedNotification::class);
});

test('the same event twice is handled once', function () {
    $team = Team::factory()->onTrial()->owned()->create(['stripe_id' => 'cus_1']);
    $event = FakeStripe::event('customer.subscription.created', FakeStripe::subscription('sub_1', 'cus_1', 'price_test_pro'));

    stripeWebhook($event)->assertOk();
    stripeWebhook($event)->assertOk()->assertSeeText('Already handled');

    Notification::assertSentToTimes(stripeWebhookOwner($team), PlanStartedNotification::class, 1);

    expect(StripeEvent::query()->pluck('id')->all())->toBe([$event['id']]);
});

test('an older update never overwrites a newer one', function () {
    $team = Team::factory()->subscribed(Plan::Pro)->owned()->create();
    $now = now()->getTimestamp();

    stripeWebhook(FakeStripe::event('customer.subscription.updated', stripeWebhookSubscription($team), ['created' => $now]))->assertOk();
    stripeWebhook(FakeStripe::event('customer.subscription.updated', stripeWebhookSubscription($team, 'past_due'), ['created' => $now - 60]))->assertOk();

    expect($team->fresh()->subscription('default')->stripe_status)->toBe('active')
        ->and(stripeWebhookStatus($team)->state)->toBe(BillingState::Active);
});

test('a newer update is applied', function () {
    $team = Team::factory()->subscribed(Plan::Pro)->owned()->create();
    $now = now()->getTimestamp();

    stripeWebhook(FakeStripe::event('customer.subscription.updated', stripeWebhookSubscription($team), ['created' => $now - 60]))->assertOk();
    stripeWebhook(FakeStripe::event('customer.subscription.updated', stripeWebhookSubscription($team, 'past_due'), ['created' => $now]))->assertOk();

    expect(stripeWebhookStatus($team)->state)->toBe(BillingState::PastDue);
});

test('cancelling at the period end is mirrored', function () {
    $team = Team::factory()->subscribed(Plan::Pro)->owned()->create();
    $item = $team->subscription('default')->items()->value('stripe_id');
    $periodEnd = CarbonImmutable::parse('2026-11-18 09:00:00')->getTimestamp();

    FakeStripe::fake([
        FakeStripe::url("subscription_items/{$item}*") => Http::response(['id' => $item, 'object' => 'subscription_item', 'current_period_end' => $periodEnd]),
    ]);

    stripeWebhook(FakeStripe::event('customer.subscription.updated', stripeWebhookSubscription($team, 'active', [
        'cancel_at_period_end' => true,
        'items' => ['data' => [['current_period_end' => $periodEnd]]],
    ])))->assertOk();

    $status = stripeWebhookStatus($team);

    expect($status->state)->toBe(BillingState::Ending)
        ->and($status->endsAt?->toDateTimeString())->toBe('2026-11-18 09:00:00');
});

test('an expired incomplete subscription is removed and acknowledged', function () {
    $team = Team::factory()->subscribed(Plan::Pro, 'incomplete')->owned()->create();

    $event = FakeStripe::event('customer.subscription.updated', stripeWebhookSubscription($team, 'incomplete_expired'));

    stripeWebhook($event)->assertOk();

    expect($team->fresh()->subscriptions()->count())->toBe(0)
        ->and(StripeEvent::query()->whereKey($event['id'])->exists())->toBeTrue();
});

test('a deleted subscription ends the plan and says so', function () {
    $team = Team::factory()->subscribed(Plan::Pro)->owned()->create();

    stripeWebhook(FakeStripe::event('customer.subscription.deleted', stripeWebhookSubscription($team, 'canceled')))->assertOk();

    $status = stripeWebhookStatus($team);

    expect($status->state)->toBe(BillingState::ReadOnly)
        ->and($status->reason)->toBe(ReadOnlyReason::PlanEnded)
        ->and(stripeWebhookActivity('plan_ended')?->subject_id)->toBe($team->getKey());

    Notification::assertSentTo(stripeWebhookOwner($team), PlanEndedNotification::class);
});

test('deleting one subscription says nothing while another still runs', function () {
    $team = Team::factory()->subscribed(Plan::Pro)->owned()->create();
    $other = Subscription::factory()->for($team, 'owner')->plan(Plan::Business)->create(['type' => 'second']);

    stripeWebhook(FakeStripe::event('customer.subscription.deleted', FakeStripe::subscription((string) $other->stripe_id, (string) $team->stripe_id, 'price_test_business', 'canceled')))->assertOk();

    expect($other->fresh()->stripe_status)->toBe('canceled')
        ->and(stripeWebhookActivity('plan_ended'))->toBeNull();

    Notification::assertNothingSent();
});

test('deleting a subscription that never ran says nothing', function () {
    $team = Team::factory()->subscribed(Plan::Pro, 'incomplete_expired')->owned()->create();

    stripeWebhook(FakeStripe::event('customer.subscription.deleted', stripeWebhookSubscription($team, 'incomplete_expired')))->assertOk();

    expect(stripeWebhookActivity('plan_ended'))->toBeNull();

    Notification::assertNothingSent();
});

test('a failed invoice warns the owners with stripe\'s next attempt', function () {
    $team = Team::factory()->subscribed(Plan::Pro, 'past_due')->owned()->create(['name' => 'Acme Labs']);
    $owner = stripeWebhookOwner($team);

    stripeWebhook(FakeStripe::event('invoice.payment_failed', FakeStripe::invoice('in_1', (string) $team->stripe_id, [
        'number' => 'MNV-0009',
        'status' => 'open',
        'attempt_count' => 1,
        'next_payment_attempt' => CarbonImmutable::parse('2026-10-21 09:00:00')->getTimestamp(),
        'status_transitions' => ['paid_at' => null],
    ])))->assertOk();

    $invoice = TeamInvoice::query()->where('stripe_id', 'in_1')->firstOrFail();

    expect($invoice->status)->toBe('open')
        ->and($invoice->attempt_count)->toBe(1)
        ->and($invoice->next_attempt_at?->toDateString())->toBe('2026-10-21')
        ->and(stripeWebhookActivity('payment_failed')?->properties->all())->toBe(['invoice' => 'MNV-0009', 'next_attempt' => '2026-10-21T09:00:00+00:00']);

    Notification::assertSentTo($owner, PaymentFailedNotification::class, function (PaymentFailedNotification $notification) use ($owner): bool {
        $mail = $notification->toMail($owner);

        return $mail->subject === 'Payment failed for Acme Labs'
            && in_array("Stripe could not charge the card for Acme Labs's Pro plan.", $mail->introLines, true)
            && in_array('It tries again on Oct 21.', $mail->introLines, true);
    });
});

test('a paid invoice is listed on the billing tab', function () {
    $team = Team::factory()->subscribed(Plan::Pro)->owned()->create();

    stripeWebhook(FakeStripe::event('invoice.paid', FakeStripe::invoice('in_2', (string) $team->stripe_id, ['number' => 'MNV-0010'])))->assertOk();

    expect($team->billingInvoices()->pluck('number')->all())->toBe(['MNV-0010']);

    Notification::assertNothingSent();
});

test('a completed checkout makes the paying card the default', function () {
    $team = Team::factory()->onTrial()->owned()->create(['stripe_id' => 'cus_1']);
    $team->forceFill(['pending_checkout_id' => 'cs_1'])->save();

    FakeStripe::fake([
        FakeStripe::url('subscriptions/sub_1*') => Http::response(FakeStripe::subscription('sub_1', 'cus_1', 'price_test_pro', 'active', ['default_payment_method' => 'pm_1'])),
        FakeStripe::url('customers/cus_1*') => fn(Request $request) => Http::response(FakeStripe::customer('cus_1', $request->method() === 'POST' ? ['invoice_settings' => ['default_payment_method' => 'pm_1']] : [])),
        FakeStripe::url('payment_methods/pm_1/attach') => Http::response(array_replace(FakeStripe::paymentMethod('pm_1'), ['customer' => 'cus_1'])),
        FakeStripe::url('payment_methods/pm_1*') => Http::response(FakeStripe::paymentMethod('pm_1')),
    ]);

    stripeWebhook(FakeStripe::event('checkout.session.completed', FakeStripe::checkoutSession('cs_1', 'https://checkout.stripe.com/c/pay/cs_1', [
        'status' => 'complete',
        'customer' => 'cus_1',
        'subscription' => 'sub_1',
    ])))->assertOk();

    Http::assertSent(fn(Request $request): bool => $request->method() === 'POST'
        && str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/v1/customers/cus_1')
        && ($request['invoice_settings']['default_payment_method'] ?? null) === 'pm_1');

    $team->refresh();

    expect($team->pm_last_four)->toBe('4242')
        ->and($team->pm_expires)->toBe('08/28')
        ->and($team->pending_checkout_id)->toBeNull();
});

test('a one-off payment checkout is acknowledged and left alone', function () {
    $team = Team::factory()->onTrial()->owned()->create(['stripe_id' => 'cus_1']);
    $team->forceFill(['pending_checkout_id' => 'cs_1'])->save();

    stripeWebhook(FakeStripe::event('checkout.session.completed', FakeStripe::checkoutSession('cs_1', 'https://checkout.stripe.com/c/pay/cs_1', [
        'mode' => 'payment',
        'customer' => 'cus_1',
    ])))->assertOk();

    expect($team->fresh()->pending_checkout_id)->toBe('cs_1');

    Http::assertNothingSent();
});

test('a customer update without a default card keeps the card shown', function () {
    $team = Team::factory()->subscribed(Plan::Pro)->owned()->create();
    $team->forceFill(['pm_type' => 'visa', 'pm_last_four' => '4242', 'pm_expires' => '08/28'])->save();

    stripeWebhook(FakeStripe::event('customer.updated', FakeStripe::customer((string) $team->stripe_id)))->assertOk();

    expect($team->fresh()->pm_last_four)->toBe('4242');

    Http::assertNothingSent();
});

test('events for customers this panel does not know are acknowledged', function () {
    $events = [
        FakeStripe::event('customer.subscription.created', FakeStripe::subscription('sub_9', 'cus_unknown', 'price_test_pro')),
        FakeStripe::event('customer.subscription.updated', FakeStripe::subscription('sub_9', 'cus_unknown', 'price_test_pro')),
        FakeStripe::event('customer.subscription.deleted', FakeStripe::subscription('sub_9', 'cus_unknown', 'price_test_pro', 'canceled')),
        FakeStripe::event('invoice.payment_failed', FakeStripe::invoice('in_9', 'cus_unknown')),
        FakeStripe::event('checkout.session.completed', FakeStripe::checkoutSession('cs_9', 'https://checkout.stripe.com/c/pay/cs_9', ['customer' => 'cus_unknown', 'subscription' => 'sub_9'])),
    ];

    foreach ($events as $event) {
        stripeWebhook($event)->assertOk();
    }

    expect(Subscription::query()->count())->toBe(0)
        ->and(TeamInvoice::query()->count())->toBe(0);

    Http::assertNothingSent();
    Notification::assertNothingSent();
});

test('a handler that fails is not marked handled', function () {
    $team = Team::factory()->subscribed(Plan::Pro)->owned()->create();
    app()->bind(InvoiceMirror::class, fn() => throw new RuntimeException('The invoice mirror failed.'));

    stripeWebhook(FakeStripe::event('invoice.paid', FakeStripe::invoice('in_3', (string) $team->stripe_id)))->assertServerError();

    expect(StripeEvent::query()->count())->toBe(0);
});

test('a malformed event is refused', function (string $body) {
    stripeWebhook($body)->assertStatus(400)->assertSeeText('Malformed event');

    expect(StripeEvent::query()->count())->toBe(0);
})->with([
    'not json' => ['nonsense'],
    'no id' => ['{"type":"invoice.paid"}'],
    'no type' => ['{"id":"evt_1"}'],
    'a list' => ['[1,2]'],
]);

test('the webhook route is cloud only, throttled and signature checked', function () {
    expect(Route::getRoutes()->getByName('cashier.webhook')->gatherMiddleware())
        ->toBe(['api', 'cloud', 'throttle:240,1,stripe-webhook', VerifyStripeSignature::class])
        ->and(route('cashier.webhook', [], false))->toBe('/api/stripe/webhook');
});

test('a deletion is not undone by an older update', function () {
    $team = Team::factory()->subscribed(Plan::Pro)->owned()->create();
    $now = now()->getTimestamp();

    stripeWebhook(FakeStripe::event('customer.subscription.deleted', stripeWebhookSubscription($team, 'canceled'), ['created' => $now]))->assertOk();
    stripeWebhook(FakeStripe::event('customer.subscription.updated', stripeWebhookSubscription($team), ['created' => $now - 60]))->assertOk();

    $subscription = $team->fresh()->subscription('default');

    expect($subscription->stripe_status)->toBe('canceled')
        ->and($subscription->synced_at?->getTimestamp())->toBe($now)
        ->and(stripeWebhookStatus($team)->reason)->toBe(ReadOnlyReason::PlanEnded);
});

test('a deletion delivered after an older update still ends the plan', function () {
    $team = Team::factory()->subscribed(Plan::Pro)->owned()->create();
    $now = now()->getTimestamp();

    stripeWebhook(FakeStripe::event('customer.subscription.updated', stripeWebhookSubscription($team), ['created' => $now - 60]))->assertOk();
    stripeWebhook(FakeStripe::event('customer.subscription.deleted', stripeWebhookSubscription($team, 'canceled'), ['created' => $now]))->assertOk();

    expect($team->fresh()->subscription('default')->stripe_status)->toBe('canceled')
        ->and(stripeWebhookStatus($team)->reason)->toBe(ReadOnlyReason::PlanEnded);
});

test('a live update never revives an ended subscription, even a newer one', function () {
    $team = Team::factory()->subscribed(Plan::Pro)->owned()->create();
    $now = now()->getTimestamp();

    stripeWebhook(FakeStripe::event('customer.subscription.deleted', stripeWebhookSubscription($team, 'canceled'), ['created' => $now]))->assertOk();
    stripeWebhook(FakeStripe::event('customer.subscription.updated', stripeWebhookSubscription($team), ['created' => $now + 60]))->assertOk();

    expect($team->fresh()->subscription('default')->stripe_status)->toBe('canceled');
});

test('a newer event about an ended subscription still applies', function () {
    $team = Team::factory()->subscribed(Plan::Pro)->owned()->create();
    $now = now()->getTimestamp();
    $ended = $now - 3600;

    stripeWebhook(FakeStripe::event('customer.subscription.deleted', stripeWebhookSubscription($team, 'canceled'), ['created' => $now]))->assertOk();
    stripeWebhook(FakeStripe::event('customer.subscription.updated', stripeWebhookSubscription($team, 'canceled', ['canceled_at' => $ended]), ['created' => $now + 60]))->assertOk();

    expect($team->fresh()->subscription('default')->ends_at?->getTimestamp())->toBe($ended);
});

test('a subscription deleted before its first events arrive stays ended', function () {
    $team = Team::factory()->onTrial()->owned()->create(['stripe_id' => 'cus_1']);
    $now = now()->getTimestamp();

    stripeWebhook(FakeStripe::event('customer.subscription.deleted', FakeStripe::subscription('sub_1', 'cus_1', 'price_test_pro', 'canceled', ['canceled_at' => $now, 'ended_at' => $now]), ['created' => $now]))->assertOk();

    stripeWebhook(FakeStripe::event('customer.subscription.created', FakeStripe::subscription('sub_1', 'cus_1', 'price_test_pro'), ['created' => $now - 60]))->assertOk();
    stripeWebhook(FakeStripe::event('customer.subscription.updated', FakeStripe::subscription('sub_1', 'cus_1', 'price_test_pro'), ['created' => $now - 30]))->assertOk();

    $subscription = $team->subscriptions()->sole();

    expect($subscription->stripe_status)->toBe('canceled')
        ->and($subscription->ends_at)->not->toBeNull()
        ->and($subscription->synced_at?->getTimestamp())->toBe($now)
        ->and(stripeWebhookStatus($team)->reason)->toBe(ReadOnlyReason::PlanEnded)
        ->and(stripeWebhookActivity('plan_started'))->toBeNull()
        ->and(stripeWebhookActivity('plan_ended'))->toBeNull();

    Notification::assertNothingSent();
});

test('a checkout that expired unpaid leaves no subscription and keeps the trial', function () {
    $team = Team::factory()->onTrial(9)->owned()->create(['stripe_id' => 'cus_1']);

    stripeWebhook(FakeStripe::event('customer.subscription.deleted', FakeStripe::subscription('sub_1', 'cus_1', 'price_test_pro', 'incomplete_expired')))->assertOk();

    expect($team->subscriptions()->count())->toBe(0)
        ->and($team->fresh()->trial_ends_at)->not->toBeNull();

    Notification::assertNothingSent();
});

test('a plan the check saw ended is not revived by an older update', function () {
    $team = Team::factory()->subscribed(Plan::Pro)->owned()->create();

    FakeStripe::fake([FakeStripe::url('subscriptions/*') => Http::response(stripeWebhookSubscription($team, 'canceled', ['ended_at' => now()->getTimestamp()]))]);

    expect(fn() => app(SubscriptionCheck::class)->confirm($team->fresh()))->toThrow(PlanRefusal::class);

    stripeWebhook(FakeStripe::event('customer.subscription.updated', stripeWebhookSubscription($team), ['created' => now()->getTimestamp() - 60]))->assertOk();

    $subscription = $team->fresh()->subscription('default');

    expect($subscription->stripe_status)->toBe('canceled')
        ->and($subscription->synced_at?->toDateTimeString())->toBe('2026-10-18 12:00:00');
});

test('a plan stripe no longer has is not revived by an older update', function () {
    $team = Team::factory()->subscribed(Plan::Pro)->owned()->create();

    FakeStripe::fake([FakeStripe::url('subscriptions/*') => FakeStripe::error('invalid_request_error', 'No such subscription', 404, 'resource_missing')]);

    expect(fn() => app(SubscriptionCheck::class)->confirm($team->fresh()))->toThrow(PlanRefusal::class);

    stripeWebhook(FakeStripe::event('customer.subscription.updated', stripeWebhookSubscription($team), ['created' => now()->getTimestamp() - 60]))->assertOk();

    $subscription = $team->fresh()->subscription('default');

    expect($subscription->stripe_status)->toBe('canceled')
        ->and($subscription->synced_at?->toDateTimeString())->toBe('2026-10-18 12:00:00');
});

test('events from the same second keep the further-along state in either order', function (array $one, array $other, string $expected) {
    foreach ([[$one, $other], [$other, $one]] as $order) {
        $team = Team::factory()->onTrial()->owned()->create(['stripe_id' => 'cus_' . Str::lower(Str::random(10))]);

        foreach ($order as [$type, $status]) {
            stripeWebhook(FakeStripe::event("customer.subscription.{$type}", FakeStripe::subscription('sub_' . $team->stripe_id, (string) $team->stripe_id, 'price_test_pro', $status)))->assertOk();
        }

        expect($team->subscriptions()->sole()->stripe_status)->toBe($expected);
    }
})->with([
    'a checkout paying' => [['created', 'incomplete'], ['updated', 'active'], 'active'],
    'a trial ending' => [['updated', 'trialing'], ['updated', 'active'], 'active'],
    'a trial starting' => [['created', 'incomplete'], ['updated', 'trialing'], 'trialing'],
    'a payment failing' => [['updated', 'active'], ['updated', 'past_due'], 'past_due'],
    'retries running out' => [['updated', 'past_due'], ['updated', 'unpaid'], 'unpaid'],
    'a plan ending' => [['updated', 'active'], ['deleted', 'canceled'], 'canceled'],
    'a failed plan ending' => [['updated', 'unpaid'], ['deleted', 'canceled'], 'canceled'],
]);

test('a same-second event that lost changes nothing else', function () {
    $team = Team::factory()->onTrial()->owned()->create(['stripe_id' => 'cus_1']);
    $renews = CarbonImmutable::parse('2026-11-18 09:00:00');

    stripeWebhook(FakeStripe::event('customer.subscription.updated', FakeStripe::subscription('sub_1', 'cus_1', 'price_test_pro', 'active', [
        'items' => ['data' => [['current_period_end' => $renews->getTimestamp(), 'price' => ['unit_amount' => 1500, 'currency' => 'usd']]]],
    ])))->assertOk();
    stripeWebhook(FakeStripe::event('customer.subscription.created', FakeStripe::subscription('sub_1', 'cus_1', 'price_test_pro', 'incomplete', [
        'items' => ['data' => [['current_period_end' => $renews->addDay()->getTimestamp(), 'price' => ['unit_amount' => 1700, 'currency' => 'usd']]]],
    ])))->assertOk();

    $subscription = $team->subscriptions()->sole();

    expect($subscription->stripe_status)->toBe('active')
        ->and($subscription->renews_at?->toDateTimeString())->toBe('2026-11-18 09:00:00')
        ->and($subscription->unit_amount)->toBe(1500);
});

test('same-second events in the same state apply in the order they arrive', function () {
    $team = Team::factory()->subscribed(Plan::Pro)->owned()->create();
    $ends = now()->addDays(5)->getTimestamp();

    stripeWebhook(FakeStripe::event('customer.subscription.updated', stripeWebhookSubscription($team)))->assertOk();
    stripeWebhook(FakeStripe::event('customer.subscription.updated', stripeWebhookSubscription($team, 'active', ['cancel_at' => $ends])))->assertOk();

    expect($team->fresh()->subscription('default')->ends_at?->getTimestamp())->toBe($ends);
});

test('plan started is said once when the update is processed before the creation', function () {
    $team = Team::factory()->onTrial()->owned()->create(['stripe_id' => 'cus_1']);
    $subscription = FakeStripe::subscription('sub_1', 'cus_1', 'price_test_pro');

    stripeWebhook(FakeStripe::event('customer.subscription.updated', $subscription))->assertOk();
    stripeWebhook(FakeStripe::event('customer.subscription.created', $subscription))->assertOk();

    Notification::assertSentToTimes(stripeWebhookOwner($team), PlanStartedNotification::class, 1);

    expect(Activity::query()->where('description', 'plan_started')->count())->toBe(1)
        ->and($team->fresh()->trial_ends_at)->toBeNull();
});

test('plan started is said when an earlier delivery stored the subscription but did not finish', function () {
    $team = Team::factory()->onTrial()->owned()->create(['stripe_id' => 'cus_1']);

    Subscription::factory()->for($team, 'owner')->plan(Plan::Pro)->create(['stripe_id' => 'sub_1', 'synced_at' => null]);

    stripeWebhook(FakeStripe::event('customer.subscription.created', FakeStripe::subscription('sub_1', 'cus_1', 'price_test_pro')))->assertOk();

    Notification::assertSentToTimes(stripeWebhookOwner($team), PlanStartedNotification::class, 1);
});

test('plan started is said once when the creation is processed first', function () {
    $team = Team::factory()->onTrial()->owned()->create(['stripe_id' => 'cus_1']);
    $subscription = FakeStripe::subscription('sub_1', 'cus_1', 'price_test_pro');

    stripeWebhook(FakeStripe::event('customer.subscription.created', $subscription))->assertOk();
    stripeWebhook(FakeStripe::event('customer.subscription.updated', $subscription))->assertOk();

    Notification::assertSentToTimes(stripeWebhookOwner($team), PlanStartedNotification::class, 1);

    expect(Activity::query()->where('description', 'plan_started')->count())->toBe(1);
});

test('a running subscription does not start again on its next update', function () {
    $team = Team::factory()->subscribed(Plan::Pro)->owned()->create();

    stripeWebhook(FakeStripe::event('customer.subscription.updated', stripeWebhookSubscription($team)))->assertOk();

    expect(stripeWebhookActivity('plan_started'))->toBeNull();

    Notification::assertNothingSent();
});

test('a subscription first heard of after it ended does not say the plan started', function () {
    $team = Team::factory()->onTrial()->owned()->create(['stripe_id' => 'cus_1']);

    stripeWebhook(FakeStripe::event('customer.subscription.updated', FakeStripe::subscription('sub_1', 'cus_1', 'price_test_pro', 'canceled', ['canceled_at' => now()->subHour()->getTimestamp()])))->assertOk();

    expect($team->subscriptions()->sole()->stripe_status)->toBe('canceled')
        ->and(stripeWebhookActivity('plan_started'))->toBeNull();

    Notification::assertNothingSent();
});

test('a completed checkout whose subscription event has not arrived cannot be started again', function () {
    $team = Team::factory()->onTrial(9)->owned()->create(['stripe_id' => 'cus_1']);
    $team->forceFill(['pending_checkout_id' => 'cs_1'])->save();
    $owner = stripeWebhookOwner($team);
    $periodEnd = CarbonImmutable::parse('2026-10-27 12:00:00');
    $subscription = FakeStripe::subscription('sub_1', 'cus_1', 'price_test_pro', 'trialing', [
        'trial_end' => $periodEnd->getTimestamp(),
        'items' => ['data' => [['current_period_end' => $periodEnd->getTimestamp(), 'price' => ['unit_amount' => 1900, 'currency' => 'usd']]]],
    ]);

    FakeStripe::fake([
        FakeStripe::url('subscriptions/sub_1*') => Http::response($subscription),
        FakeStripe::url('checkout/sessions') => Http::response(FakeStripe::checkoutSession('cs_2', 'https://checkout.stripe.com/c/pay/cs_2')),
    ]);

    stripeWebhook(FakeStripe::event('checkout.session.completed', FakeStripe::checkoutSession('cs_1', 'https://checkout.stripe.com/c/pay/cs_1', [
        'status' => 'complete',
        'customer' => 'cus_1',
        'subscription' => 'sub_1',
    ]), ['created' => now()->getTimestamp() - 10]))->assertOk();

    $mirrored = $team->fresh()->subscription('default');
    $status = stripeWebhookStatus($team);

    expect($team->fresh()->pending_checkout_id)->toBeNull()
        ->and($mirrored->stripe_id)->toBe('sub_1')
        ->and($mirrored->items()->count())->toBe(1)
        ->and($mirrored->synced_at?->toDateTimeString())->toBe('2026-10-18 11:59:50')
        ->and($mirrored->renews_at?->toDateTimeString())->toBe('2026-10-27 12:00:00')
        ->and($mirrored->unit_amount)->toBe(1900)
        ->and($status->state)->toBe(BillingState::Trialing)
        ->and($status->plan)->toBe(Plan::Pro)
        ->and($team->fresh()->trial_ends_at)->toBeNull();

    $this->actingAs($owner)->put(route('settings.billing.plan.update'), ['plan' => 'pro'])
        ->assertSessionHasErrors(['plan' => 'The team is already on Pro. Choose a different plan.']);
    $this->actingAs($owner)->put(route('settings.billing.plan.update'), ['plan' => 'business'])
        ->assertSessionHasNoErrors();

    Http::assertNotSent(fn(Request $request): bool => str_contains($request->url(), '/v1/checkout/sessions'));

    stripeWebhook(FakeStripe::event('customer.subscription.created', $subscription))->assertOk();
    stripeWebhook(FakeStripe::event('customer.subscription.updated', $subscription, ['created' => now()->getTimestamp() - 60]))->assertOk();

    Notification::assertSentToTimes($owner, PlanStartedNotification::class, 1);

    expect($team->subscriptions()->count())->toBe(1);
});

test('a completed checkout stays pending until its subscription can be read', function () {
    $team = Team::factory()->onTrial()->owned()->create(['stripe_id' => 'cus_1']);
    $team->forceFill(['pending_checkout_id' => 'cs_1'])->save();
    $stripeUp = false;

    FakeStripe::fake([
        FakeStripe::url('subscriptions/sub_1*') => function () use (&$stripeUp) {
            return $stripeUp ? Http::response(FakeStripe::subscription('sub_1', 'cus_1', 'price_test_pro')) : FakeStripe::error('api_error', "Something went wrong on Stripe's end.", 500);
        },
    ]);

    $event = FakeStripe::event('checkout.session.completed', FakeStripe::checkoutSession('cs_1', 'https://checkout.stripe.com/c/pay/cs_1', [
        'status' => 'complete',
        'customer' => 'cus_1',
        'subscription' => 'sub_1',
    ]));

    stripeWebhook($event)->assertServerError();

    expect($team->fresh()->pending_checkout_id)->toBe('cs_1')
        ->and($team->subscriptions()->count())->toBe(0)
        ->and(StripeEvent::query()->count())->toBe(0);

    $stripeUp = true;

    stripeWebhook($event)->assertOk();

    expect($team->fresh()->pending_checkout_id)->toBeNull()
        ->and($team->subscriptions()->count())->toBe(1);
});

test('a completed checkout stays pending until its card is stored', function () {
    $team = Team::factory()->onTrial()->owned()->create(['stripe_id' => 'cus_1']);
    $team->forceFill(['pending_checkout_id' => 'cs_1'])->save();
    $stripeUp = false;

    FakeStripe::fake([
        FakeStripe::url('subscriptions/sub_1*') => Http::response(FakeStripe::subscription('sub_1', 'cus_1', 'price_test_pro', 'active', ['default_payment_method' => 'pm_1'])),
        FakeStripe::url('customers/cus_1*') => function (Request $request) use (&$stripeUp) {
            if ($request->method() === 'POST' && !$stripeUp) {
                return FakeStripe::error('api_error', "Something went wrong on Stripe's end.", 500);
            }

            return Http::response(FakeStripe::customer('cus_1', $request->method() === 'POST' ? ['invoice_settings' => ['default_payment_method' => 'pm_1']] : []));
        },
        FakeStripe::url('payment_methods/pm_1/attach') => Http::response(array_replace(FakeStripe::paymentMethod('pm_1'), ['customer' => 'cus_1'])),
        FakeStripe::url('payment_methods/pm_1*') => Http::response(FakeStripe::paymentMethod('pm_1')),
    ]);

    $event = FakeStripe::event('checkout.session.completed', FakeStripe::checkoutSession('cs_1', 'https://checkout.stripe.com/c/pay/cs_1', [
        'status' => 'complete',
        'customer' => 'cus_1',
        'subscription' => 'sub_1',
    ]));

    stripeWebhook($event)->assertServerError();

    expect($team->fresh()->pending_checkout_id)->toBe('cs_1')
        ->and($team->fresh()->pm_last_four)->toBeNull()
        ->and(StripeEvent::query()->count())->toBe(0);

    $stripeUp = true;

    stripeWebhook($event)->assertOk();

    expect($team->fresh()->pending_checkout_id)->toBeNull()
        ->and($team->fresh()->pm_last_four)->toBe('4242')
        ->and($team->subscriptions()->count())->toBe(1);
});

test('a completed checkout leaves a subscription it already knows to its own events', function () {
    $team = Team::factory()->onTrial()->owned()->create(['stripe_id' => 'cus_1']);
    $now = now()->getTimestamp();

    FakeStripe::fake([FakeStripe::url('subscriptions/sub_1*') => Http::response(FakeStripe::subscription('sub_1', 'cus_1', 'price_test_pro'))]);

    stripeWebhook(FakeStripe::event('customer.subscription.created', FakeStripe::subscription('sub_1', 'cus_1', 'price_test_pro', 'incomplete'), ['created' => $now - 5]))->assertOk();
    stripeWebhook(FakeStripe::event('checkout.session.completed', FakeStripe::checkoutSession('cs_1', 'https://checkout.stripe.com/c/pay/cs_1', [
        'status' => 'complete',
        'customer' => 'cus_1',
        'subscription' => 'sub_1',
    ]), ['created' => $now]))->assertOk();

    expect($team->subscriptions()->sole()->synced_at?->getTimestamp())->toBe($now - 5);

    stripeWebhook(FakeStripe::event('customer.subscription.updated', FakeStripe::subscription('sub_1', 'cus_1', 'price_test_pro'), ['created' => $now - 2]))->assertOk();

    expect($team->subscriptions()->sole()->stripe_status)->toBe('active');

    Notification::assertSentToTimes(stripeWebhookOwner($team), PlanStartedNotification::class, 1);
});

test('a completed checkout with no subscription still closes the pending checkout', function () {
    $team = Team::factory()->onTrial()->owned()->create(['stripe_id' => 'cus_1']);
    $team->forceFill(['pending_checkout_id' => 'cs_1'])->save();

    stripeWebhook(FakeStripe::event('checkout.session.completed', FakeStripe::checkoutSession('cs_1', 'https://checkout.stripe.com/c/pay/cs_1', [
        'status' => 'complete',
        'customer' => 'cus_1',
    ])))->assertOk();

    expect($team->fresh()->pending_checkout_id)->toBeNull();

    Http::assertNothingSent();
});

test('a completed checkout for an older session leaves the pending one alone', function () {
    $team = Team::factory()->onTrial()->owned()->create(['stripe_id' => 'cus_1']);
    $team->forceFill(['pending_checkout_id' => 'cs_2'])->save();

    stripeWebhook(FakeStripe::event('checkout.session.completed', FakeStripe::checkoutSession('cs_1', 'https://checkout.stripe.com/c/pay/cs_1', [
        'status' => 'complete',
        'customer' => 'cus_1',
    ])))->assertOk();

    expect($team->fresh()->pending_checkout_id)->toBe('cs_2');
});

test('a subscription mirrors what its price costs', function () {
    $team = Team::factory()->onTrial()->owned()->create(['stripe_id' => 'cus_1']);
    $now = now()->getTimestamp();
    $priced = fn(?int $amount, ?string $currency): array => FakeStripe::subscription('sub_1', 'cus_1', 'price_test_pro', 'active', [
        'items' => ['data' => [['price' => ['unit_amount' => $amount, 'currency' => $currency]]]],
    ]);

    stripeWebhook(FakeStripe::event('customer.subscription.created', $priced(1500, 'usd'), ['created' => $now]))->assertOk();

    $subscription = $team->subscriptions()->sole();

    expect($subscription->unit_amount)->toBe(1500)
        ->and($subscription->currency)->toBe('usd');

    stripeWebhook(FakeStripe::event('customer.subscription.updated', $priced(1700, 'EUR'), ['created' => $now + 1]))->assertOk();

    $subscription->refresh();

    expect($subscription->unit_amount)->toBe(1700)
        ->and($subscription->currency)->toBe('eur');

    stripeWebhook(FakeStripe::event('customer.subscription.updated', $priced(null, null), ['created' => $now + 2]))->assertOk();

    $subscription->refresh();

    expect($subscription->unit_amount)->toBeNull()
        ->and($subscription->currency)->toBeNull();
});

test('a subscription with several prices mirrors no single amount', function () {
    $team = Team::factory()->onTrial()->owned()->create(['stripe_id' => 'cus_1']);

    stripeWebhook(FakeStripe::event('customer.subscription.created', FakeStripe::subscription('sub_1', 'cus_1', 'price_test_pro', 'active', [
        'items' => ['data' => [
            ['price' => ['unit_amount' => 1500, 'currency' => 'usd']],
            ['id' => 'si_2', 'object' => 'subscription_item', 'quantity' => 1, 'price' => ['id' => 'price_other', 'object' => 'price', 'product' => 'prod_other', 'unit_amount' => 500, 'currency' => 'usd']],
        ]],
    ])))->assertOk();

    expect($team->subscriptions()->sole()->unit_amount)->toBeNull();
});

test('a deleted customer ends the plan, clears the card and says so once', function (bool $subscriptionFirst) {
    $team = Team::factory()->subscribed(Plan::Pro)->owned()->create();
    $team->forceFill(['pm_type' => 'visa', 'pm_last_four' => '4242', 'pm_expires' => '08/28', 'pending_checkout_id' => 'cs_1'])->save();

    $events = [
        FakeStripe::event('customer.deleted', FakeStripe::customer((string) $team->stripe_id)),
        FakeStripe::event('customer.subscription.deleted', stripeWebhookSubscription($team, 'canceled')),
    ];

    foreach ($subscriptionFirst ? array_reverse($events) : $events as $event) {
        stripeWebhook($event)->assertOk();
    }

    $fresh = $team->fresh();

    expect($fresh->stripe_id)->toBeNull()
        ->and($fresh->pm_type)->toBeNull()
        ->and($fresh->pm_last_four)->toBeNull()
        ->and($fresh->pm_expires)->toBeNull()
        ->and($fresh->pending_checkout_id)->toBeNull()
        ->and(stripeWebhookStatus($team)->reason)->toBe(ReadOnlyReason::PlanEnded)
        ->and(Activity::query()->where('description', 'plan_ended')->count())->toBe(1);

    Notification::assertSentToTimes(stripeWebhookOwner($team), PlanEndedNotification::class, 1);
})->with(['customer first' => false, 'subscription first' => true]);

test('deleting a customer with no running plan says nothing', function () {
    $team = Team::factory()->onTrial()->owned()->create(['stripe_id' => 'cus_1', 'pm_expires' => '08/28']);

    stripeWebhook(FakeStripe::event('customer.deleted', FakeStripe::customer('cus_1')))->assertOk();

    expect($team->fresh()->pm_expires)->toBeNull()
        ->and(stripeWebhookActivity('plan_ended'))->toBeNull();

    Notification::assertNothingSent();
});

test('deleting a customer this panel does not know is acknowledged', function () {
    stripeWebhook(FakeStripe::event('customer.deleted', FakeStripe::customer('cus_unknown')))->assertOk();

    Notification::assertNothingSent();
});

test('a failed payment overtaken by a finished invoice only warns while something is still owed', function (string $finished, bool $warns) {
    $team = Team::factory()->subscribed(Plan::Pro)->owned()->create();
    $now = now()->getTimestamp();

    stripeWebhook(FakeStripe::event('invoice.paid', FakeStripe::invoice('in_1', (string) $team->stripe_id, ['status' => $finished]), ['created' => $now]))->assertOk();
    stripeWebhook(FakeStripe::event('invoice.payment_failed', FakeStripe::invoice('in_1', (string) $team->stripe_id, [
        'status' => 'open',
        'attempt_count' => 1,
        'status_transitions' => ['paid_at' => null],
    ]), ['created' => $now - 60]))->assertOk();

    expect(TeamInvoice::query()->where('stripe_id', 'in_1')->value('status'))->toBe($finished)
        ->and(stripeWebhookActivity('payment_failed') !== null)->toBe($warns);

    Notification::assertSentToTimes(stripeWebhookOwner($team), PaymentFailedNotification::class, $warns ? 1 : 0);
})->with([
    'paid' => ['paid', false],
    'void' => ['void', false],
    'uncollectible' => ['uncollectible', true],
]);

test('events for one customer wait for each other', function (string $type, Closure $object) {
    $team = Team::factory()->subscribed(Plan::Pro)->owned()->create(['stripe_id' => 'cus_1']);
    $lock = Cache::lock('stripe-customer:cus_1', 300);
    $event = FakeStripe::event($type, $object());

    Sleep::fake(syncWithCarbon: true);

    expect($lock->get())->toBeTrue();

    stripeWebhook($event)->assertServerError();

    expect(StripeEvent::query()->count())->toBe(0);

    $lock->release();

    stripeWebhook($event)->assertOk();

    expect(StripeEvent::query()->count())->toBe(1);
})->with([
    'about a customer' => ['customer.deleted', fn() => FakeStripe::customer('cus_1')],
    'about something of a customer' => ['invoice.paid', fn() => FakeStripe::invoice('in_1', 'cus_1')],
]);

test('an event with no customer waits only for itself', function () {
    $lock = Cache::lock('stripe-event:evt_alone', 300);
    $event = FakeStripe::event('product.updated', ['id' => 'prod_1', 'object' => 'product'], ['id' => 'evt_alone']);

    Sleep::fake(syncWithCarbon: true);

    expect($lock->get())->toBeTrue();

    stripeWebhook($event)->assertServerError();

    $lock->release();

    stripeWebhook($event)->assertOk();
});

test('the same event arriving twice at once is still handled once', function () {
    $team = Team::factory()->onTrial()->owned()->create(['stripe_id' => 'cus_1']);
    $event = FakeStripe::event('customer.subscription.created', FakeStripe::subscription('sub_1', 'cus_1', 'price_test_pro'));

    Sleep::fake(syncWithCarbon: true);

    $lock = Cache::lock('stripe-customer:cus_1', 300);

    expect($lock->get())->toBeTrue();

    stripeWebhook($event)->assertServerError();

    $lock->release();

    stripeWebhook($event)->assertOk();
    stripeWebhook($event)->assertOk()->assertSeeText('Already handled');

    Notification::assertSentToTimes(stripeWebhookOwner($team), PlanStartedNotification::class, 1);
});

test('an incomplete first event older than its 23 hours is not stored', function () {
    $team = Team::factory()->onTrial(9)->owned()->create(['stripe_id' => 'cus_1']);

    stripeWebhook(FakeStripe::event('customer.subscription.updated', FakeStripe::subscription('sub_1', 'cus_1', 'price_test_pro', 'incomplete_expired')))->assertOk();
    stripeWebhook(FakeStripe::event('customer.subscription.created', FakeStripe::subscription('sub_1', 'cus_1', 'price_test_pro', 'incomplete'), ['created' => now()->subHours(23)->subSecond()->getTimestamp()]))->assertOk();

    expect($team->subscriptions()->count())->toBe(0)
        ->and($team->fresh()->trial_ends_at)->not->toBeNull();

    Notification::assertNothingSent();
});

test('an incomplete first event inside its 23 hours is stored', function () {
    $team = Team::factory()->onTrial(9)->owned()->create(['stripe_id' => 'cus_1']);

    stripeWebhook(FakeStripe::event('customer.subscription.created', FakeStripe::subscription('sub_1', 'cus_1', 'price_test_pro', 'incomplete'), ['created' => now()->subHours(23)->getTimestamp()]))->assertOk();

    expect($team->subscriptions()->sole()->stripe_status)->toBe('incomplete');
});

test('an old first event that is not incomplete is stored', function () {
    $team = Team::factory()->onTrial(9)->owned()->create(['stripe_id' => 'cus_1']);

    stripeWebhook(FakeStripe::event('customer.subscription.created', FakeStripe::subscription('sub_1', 'cus_1', 'price_test_pro'), ['created' => now()->subDays(2)->getTimestamp()]))->assertOk();

    expect($team->subscriptions()->sole()->stripe_status)->toBe('active');
});

test('an old incomplete event for a subscription already stored still ends the free trial', function () {
    $team = Team::factory()->onTrial(9)->owned()->create(['stripe_id' => 'cus_1']);

    stripeWebhook(FakeStripe::event('customer.subscription.updated', FakeStripe::subscription('sub_1', 'cus_1', 'price_test_pro')))->assertOk();
    stripeWebhook(FakeStripe::event('customer.subscription.created', FakeStripe::subscription('sub_1', 'cus_1', 'price_test_pro', 'incomplete'), ['created' => now()->subDays(2)->getTimestamp()]))->assertOk();

    expect($team->subscriptions()->sole()->stripe_status)->toBe('active')
        ->and($team->fresh()->trial_ends_at)->toBeNull();
});

test('a retried checkout still stores what stripe says now, however old the event', function () {
    $team = Team::factory()->onTrial()->owned()->create(['stripe_id' => 'cus_1']);

    FakeStripe::fake([FakeStripe::url('subscriptions/sub_1*') => Http::response(FakeStripe::subscription('sub_1', 'cus_1', 'price_test_pro', 'incomplete'))]);

    stripeWebhook(FakeStripe::event('checkout.session.completed', FakeStripe::checkoutSession('cs_1', 'https://checkout.stripe.com/c/pay/cs_1', [
        'status' => 'complete',
        'customer' => 'cus_1',
        'subscription' => 'sub_1',
    ]), ['created' => now()->subDays(2)->getTimestamp()]))->assertOk();

    expect($team->subscriptions()->sole()->stripe_status)->toBe('incomplete');
});

test('a deleted customer leaves a plan that ended long ago with its own end date', function () {
    $team = Team::factory()->owned()->create(['stripe_id' => 'cus_1']);
    $ended = Subscription::factory()->ended(now()->subMonth())->for($team, 'owner')->create();

    stripeWebhook(FakeStripe::event('customer.deleted', FakeStripe::customer('cus_1')))->assertOk();

    expect($ended->fresh()->ends_at?->toDateString())->toBe('2026-09-18')
        ->and($team->fresh()->stripe_id)->toBeNull();

    Notification::assertNothingSent();
});

test('a deleted customer ends a plan still on its own trial', function () {
    $team = Team::factory()->owned()->create(['stripe_id' => 'cus_1']);
    $trialing = Subscription::factory()->trialing(now()->addDays(5))->for($team, 'owner')->create();

    stripeWebhook(FakeStripe::event('customer.deleted', FakeStripe::customer('cus_1')))->assertOk();

    $trialing->refresh();

    expect($trialing->stripe_status)->toBe('canceled')
        ->and($trialing->trial_ends_at)->toBeNull()
        ->and($trialing->ends_at?->toDateString())->toBe('2026-10-18');

    Notification::assertSentTo(stripeWebhookOwner($team), PlanEndedNotification::class);
});

test('a deleted customer leaves a free trial running', function () {
    $team = Team::factory()->onTrial(9)->owned()->create(['stripe_id' => 'cus_1']);

    stripeWebhook(FakeStripe::event('customer.deleted', FakeStripe::customer('cus_1')))->assertOk();

    $status = stripeWebhookStatus($team);

    expect($team->fresh()->stripe_id)->toBeNull()
        ->and($team->fresh()->trial_ends_at)->not->toBeNull()
        ->and($status->state)->toBe(BillingState::Trialing)
        ->and($status->plan)->toBe(Plan::Trial);
});
