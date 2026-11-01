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

        $existed = $team->subscriptions()->where('stripe_id', $data['id'])->exists();

        parent::handleCustomerSubscriptionCreated($payload);

        $subscription = $this->sync($team, $data, $payload);

        if (!$existed && $subscription !== null) {
            $plan = Plan::fromPriceId($subscription->stripe_price) ?? Plan::Starter;

            activity()->performedOn($team)->withProperties(['plan' => $plan->value])->log('plan_started');
            Notification::send($team->owners()->get(), new PlanStartedNotification($team, $plan));
        }

        return $this->successMethod();
    }

    protected function handleCustomerSubscriptionUpdated(array $payload)
    {
        $data = $payload['data']['object'];
        $team = $this->team($data['customer'] ?? null);

        if ($team === null) {
            return $this->successMethod();
        }

        $synced = $team->subscriptions()->where('stripe_id', $data['id'])->value('synced_at');

        if ($synced !== null && CarbonImmutable::parse($synced)->gt($this->createdAt($payload))) {
            return $this->successMethod();
        }

        if (parent::handleCustomerSubscriptionUpdated($payload) === null) {
            return $this->successMethod();
        }

        $this->sync($team, $data, $payload);

        return $this->successMethod();
    }

    protected function handleCustomerSubscriptionDeleted(array $payload)
    {
        $data = $payload['data']['object'];
        $team = $this->team($data['customer'] ?? null);
        $wasRunning = $team?->subscriptions()->where('stripe_id', $data['id'])->first()?->valid() === true;

        parent::handleCustomerSubscriptionDeleted($payload);

        if ($team !== null && $wasRunning && !$team->subscriptions()->get()->contains(fn(Subscription $subscription): bool => $subscription->valid())) {
            activity()->performedOn($team)->log('plan_ended');
            Notification::send($team->owners()->get(), new PlanEndedNotification($team));
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

        if ($team->pending_checkout_id !== null && $team->pending_checkout_id === ($session['id'] ?? null)) {
            $team->forceFill(['pending_checkout_id' => null])->save();
        }

        if (is_string($session['subscription'] ?? null)) {
            $method = $team->stripe()->subscriptions->retrieve($session['subscription'])->default_payment_method;

            if (is_string($method)) {
                $team->updateDefaultPaymentMethod($method);
            }
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

    private function sync(Team $team, array $data, array $payload): ?Subscription
    {
        $subscription = $team->subscriptions()->where('stripe_id', $data['id'])->first();
        $created = $this->createdAt($payload);

        if ($subscription === null || $subscription->synced_at?->gt($created)) {
            return $subscription;
        }

        $subscription->forceFill(['renews_at' => $this->periodEnd($data), 'synced_at' => $created])->save();

        return $subscription;
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
