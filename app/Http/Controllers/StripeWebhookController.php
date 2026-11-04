<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\Plan;
use App\Models\Subscription;
use App\Models\Team;
use App\Notifications\PaymentFailedNotification;
use App\Notifications\PlanEndedNotification;
use App\Notifications\PlanStartedNotification;
use App\Services\Billing\InvoiceMirror;
use App\Services\Billing\StripeEvents;
use App\Services\Billing\TeamBilling;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Laravel\Cashier\Http\Controllers\WebhookController;
use Symfony\Component\HttpFoundation\Response;

final class StripeWebhookController extends WebhookController
{
    private const INCOMPLETE_EXPIRES_HOURS = 23;

    private const ENDED = 5;

    private const STATUS_RANKS = [
        'incomplete' => 0,
        'trialing' => 1,
        'active' => 2,
        'past_due' => 3,
        'paused' => 3,
        'unpaid' => 4,
        'incomplete_expired' => self::ENDED,
        'canceled' => self::ENDED,
    ];

    public function __construct() {}

    public function handleWebhook(Request $request): Response
    {
        $payload = json_decode($request->getContent(), true);

        if (!is_array($payload) || !is_string($payload['id'] ?? null) || !is_string($payload['type'] ?? null)) {
            return new Response('Malformed event', 400);
        }

        return app(StripeEvents::class)->once($payload, fn() => parent::handleWebhook($request));
    }

    protected function handleCustomerSubscriptionCreated(array $payload)
    {
        $data = $payload['data']['object'];
        $team = $this->team($data['customer'] ?? null);

        if ($team === null) {
            return $this->successMethod();
        }

        if ($this->localSubscription($team, $data) === null && ($data['status'] ?? null) === 'incomplete' && $this->createdAt($payload)->lt(CarbonImmutable::now()->subHours(self::INCOMPLETE_EXPIRES_HOURS))) {
            return $this->successMethod();
        }

        $this->storeCreated($team, $data, $payload);

        return $this->successMethod();
    }

    protected function handleCustomerSubscriptionUpdated(array $payload)
    {
        $data = $payload['data']['object'];
        $team = $this->team($data['customer'] ?? null);

        if ($team === null) {
            return $this->successMethod();
        }

        $subscription = $this->localSubscription($team, $data);

        if ($subscription !== null && $this->outdated($subscription, $data, $this->createdAt($payload))) {
            return $this->successMethod();
        }

        $first = $subscription?->synced_at === null;

        if (parent::handleCustomerSubscriptionUpdated($payload) === null) {
            return $this->successMethod();
        }

        $this->sync($team, $data, $payload, $first);

        return $this->successMethod();
    }

    protected function handleCustomerSubscriptionDeleted(array $payload)
    {
        $data = $payload['data']['object'];
        $team = $this->team($data['customer'] ?? null);

        if ($team === null) {
            return $this->successMethod();
        }

        $subscription = $this->localSubscription($team, $data);
        $wasRunning = $subscription?->valid() === true;

        if ($subscription === null && ($data['status'] ?? null) === 'canceled') {
            parent::handleCustomerSubscriptionCreated($payload);
        }

        parent::handleCustomerSubscriptionDeleted($payload);

        $this->localSubscription($team, $data)?->forceFill(['synced_at' => $this->createdAt($payload)])->save();

        if ($wasRunning && !$this->running($team)) {
            $this->planEnded($team);
        }

        return $this->successMethod();
    }

    protected function handleCustomerDeleted(array $payload)
    {
        $team = $this->team($payload['data']['object']['id'] ?? null);

        if ($team === null) {
            return $this->successMethod();
        }

        $wasRunning = $this->running($team);

        $team->subscriptions()->get()
            ->reject(fn(Subscription $subscription): bool => $subscription->ended())
            ->each(fn(Subscription $subscription) => $subscription->skipTrial()->markAsCanceled());

        $team->forceFill(['stripe_id' => null, 'pm_type' => null, 'pm_last_four' => null, 'pm_expires' => null, 'pending_checkout_id' => null])->save();

        if ($wasRunning) {
            $this->planEnded($team);
        }

        return $this->successMethod();
    }

    protected function handleCustomerUpdated(array $payload)
    {
        if (($payload['data']['object']['invoice_settings']['default_payment_method'] ?? null) === null) {
            return $this->successMethod();
        }

        return parent::handleCustomerUpdated($payload);
    }

    protected function handleCheckoutSessionCompleted(array $payload)
    {
        $session = $payload['data']['object'];
        $team = ($session['mode'] ?? null) === 'subscription' ? $this->team($session['customer'] ?? null) : null;

        if ($team === null) {
            return $this->successMethod();
        }

        $subscription = is_string($session['subscription'] ?? null) ? $team->stripe()->subscriptions->retrieve($session['subscription']) : null;

        if ($subscription !== null && $this->localSubscription($team, ['id' => $subscription->id]) === null) {
            $data = $subscription->toArray();

            $this->storeCreated($team, $data, ['created' => $payload['created'] ?? null, 'data' => ['object' => $data]]);
        }

        if ($team->pending_checkout_id !== null && $team->pending_checkout_id === ($session['id'] ?? null)) {
            $team->forceFill(['pending_checkout_id' => null])->save();
        }

        if (is_string($subscription?->default_payment_method)) {
            $team->updateDefaultPaymentMethod($subscription->default_payment_method);
        }

        return $this->successMethod();
    }

    protected function handleInvoiceFinalized(array $payload)
    {
        return $this->mirror($payload);
    }

    protected function handleInvoicePaid(array $payload)
    {
        return $this->mirror($payload);
    }

    protected function handleInvoiceVoided(array $payload)
    {
        return $this->mirror($payload);
    }

    protected function handleInvoiceMarkedUncollectible(array $payload)
    {
        return $this->mirror($payload);
    }

    protected function handleInvoicePaymentFailed(array $payload)
    {
        $invoice = $payload['data']['object'];
        $team = $this->team($invoice['customer'] ?? null);

        if ($team === null) {
            return $this->successMethod();
        }

        $row = app(InvoiceMirror::class)->record($team, $invoice, $this->createdAt($payload)->getTimestamp());

        if (in_array($row?->status, ['paid', 'void'], true)) {
            return $this->successMethod();
        }

        $next = $row?->next_attempt_at === null ? null : CarbonImmutable::instance($row->next_attempt_at);

        activity()->performedOn($team)->withProperties(['invoice' => $invoice['number'] ?? $invoice['id'], 'next_attempt' => $next?->toIso8601String()])->log('payment_failed');
        Notification::send($team->owners()->get(), new PaymentFailedNotification($team, app(TeamBilling::class)->status($team)->plan, $next));

        return $this->successMethod();
    }

    private function mirror(array $payload): Response
    {
        $invoice = $payload['data']['object'];
        $team = $this->team($invoice['customer'] ?? null);

        if ($team !== null) {
            app(InvoiceMirror::class)->record($team, $invoice, $this->createdAt($payload)->getTimestamp());
        }

        return $this->successMethod();
    }

    private function storeCreated(Team $team, array $data, array $payload): void
    {
        $first = $this->localSubscription($team, $data)?->synced_at === null;

        parent::handleCustomerSubscriptionCreated($payload);

        $this->sync($team, $data, $payload, $first);
    }

    private function sync(Team $team, array $data, array $payload, bool $first): void
    {
        $subscription = $this->localSubscription($team, $data);
        $created = $this->createdAt($payload);

        if ($subscription === null) {
            return;
        }

        if (!$this->outdated($subscription, $data, $created)) {
            $subscription->forceFill(['renews_at' => $this->periodEnd($data), ...$this->price($data), 'synced_at' => $created])->save();
        }

        if ($first && !$subscription->ended()) {
            $plan = Plan::fromPriceId($subscription->stripe_price) ?? Plan::Starter;

            activity()->performedOn($team)->withProperties(['plan' => $plan->value])->log('plan_started');
            Notification::send($team->owners()->get(), new PlanStartedNotification($team, $plan));
        }
    }

    private function outdated(Subscription $subscription, array $data, CarbonImmutable $created): bool
    {
        $held = self::STATUS_RANKS[$subscription->stripe_status] ?? 0;
        $incoming = self::STATUS_RANKS[$data['status'] ?? ''] ?? 0;

        return match (true) {
            $held === self::ENDED && $incoming < self::ENDED => true,
            $subscription->synced_at === null => false,
            default => $subscription->synced_at->gt($created) || ($subscription->synced_at->eq($created) && $held > $incoming),
        };
    }

    /**
     * @return array{unit_amount: ?int, currency: ?string}
     */
    private function price(array $data): array
    {
        $items = $data['items']['data'] ?? [];
        $price = count($items) === 1 ? $items[0]['price'] ?? [] : [];
        $amount = $price['unit_amount'] ?? null;
        $currency = $price['currency'] ?? null;

        return is_int($amount) && is_string($currency)
            ? ['unit_amount' => $amount, 'currency' => strtolower($currency)]
            : ['unit_amount' => null, 'currency' => null];
    }

    private function running(Team $team): bool
    {
        return $team->subscriptions()->get()->contains(fn(Subscription $subscription): bool => $subscription->valid());
    }

    private function planEnded(Team $team): void
    {
        activity()->performedOn($team)->log('plan_ended');
        Notification::send($team->owners()->get(), new PlanEndedNotification($team));
    }

    private function localSubscription(Team $team, array $data): ?Subscription
    {
        return $team->subscriptions()->where('stripe_id', $data['id'])->first();
    }

    private function periodEnd(array $data): ?CarbonImmutable
    {
        $ends = array_filter(array_map(fn(mixed $item): mixed => is_array($item) ? $item['current_period_end'] ?? null : null, $data['items']['data'] ?? []), 'is_numeric');
        $end = $ends === [] ? $data['current_period_end'] ?? null : max($ends);

        return is_numeric($end) ? CarbonImmutable::createFromTimestamp((int) $end) : null;
    }

    private function createdAt(array $payload): CarbonImmutable
    {
        return is_numeric($payload['created'] ?? null) ? CarbonImmutable::createFromTimestamp((int) $payload['created']) : CarbonImmutable::now();
    }

    private function team(mixed $customer): ?Team
    {
        $team = is_string($customer) ? $this->getUserByStripeId($customer) : null;

        return $team instanceof Team ? $team : null;
    }
}
