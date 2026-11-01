<?php

declare(strict_types=1);

use App\Enums\BillingState;
use App\Enums\Plan;
use App\Enums\ReadOnlyReason;
use App\Enums\TeamRole;
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
use App\Services\Billing\TeamBilling;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
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
        ->assertSeeText('Stripe webhooks are not configured on this panel.');

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
