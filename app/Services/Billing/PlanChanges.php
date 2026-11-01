<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\BillingState;
use App\Enums\Plan;
use App\Models\Subscription;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Laravel\Cashier\Cashier;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\InvalidRequestException;

final class PlanChanges
{
    private const PAYMENT_FAILED = ['unpaid', 'incomplete'];

    public function __construct(private readonly TeamBilling $billing, private readonly PlanChangeGuard $guard) {}

    public function change(Team $team, Plan $plan, User $user): ?string
    {
        $status = $this->billing->status($team);
        $subscription = $team->subscription('default');
        $price = $plan->checkoutPrice();

        $refusal = match (true) {
            $status->plan === $plan => "The team is already on {$plan->label()}. Choose a different plan.",
            $price === null => "{$plan->label()} is not available on this panel yet. Choose another plan.",
            $status->state === BillingState::PastDue, in_array($subscription?->stripe_status, self::PAYMENT_FAILED, true) => 'The last payment failed. Update the payment method first, then change the plan.',
            $status->state === BillingState::Ending => 'This plan is ending. Keep it first, then change it.',
            default => $this->guard->blockers($team, $plan)[0]['message'] ?? null,
        };

        if ($refusal !== null) {
            $this->refuse($refusal);
        }

        if ($subscription instanceof Subscription && $subscription->valid()) {
            $this->stripe($team, 'swap', fn() => $subscription->swap($price));

            activity()->performedOn($team)->causedBy($user)->withProperties(['from' => $status->plan?->value, 'to' => $plan->value])->log('plan_changed');

            return null;
        }

        $checkout = $this->stripe($team, 'checkout', function () use ($team, $price) {
            $this->closePendingCheckout($team);

            $builder = $team->newSubscription('default', $price)->withMetadata(['team_id' => $team->getKey()]);

            if ($team->onGenericTrial()) {
                $builder->trialUntil($team->trial_ends_at);
            }

            return $builder->checkout([
                'success_url' => route('settings.billing', ['checkout' => 'success']),
                'cancel_url' => route('settings.billing', ['checkout' => 'cancelled']),
                'client_reference_id' => $team->getKey(),
            ]);
        });

        $team->forceFill(['pending_checkout_id' => $checkout->id])->save();

        activity()->performedOn($team)->causedBy($user)->withProperties(['plan' => $plan->value])->log('checkout_started');

        return (string) $checkout->url;
    }

    public function end(Team $team, User $user): CarbonImmutable
    {
        $status = $this->billing->status($team);
        $subscription = $team->subscription('default');

        if (!$subscription instanceof Subscription || !$subscription->valid()) {
            $this->refuse('There is no plan to end. The trial ends on its own.');
        }

        if ($status->state === BillingState::Ending) {
            $this->refuse("This plan already ends on {$this->day($status->endsAt)}. Keep it to stay on {$status->plan?->label()}.");
        }

        $this->stripe($team, 'cancel', fn() => $subscription->cancel());

        activity()->performedOn($team)->causedBy($user)->log('plan_cancelled');

        return CarbonImmutable::instance($subscription->ends_at ?? $subscription->renews_at ?? now());
    }

    public function resume(Team $team, User $user): void
    {
        $subscription = $team->subscription('default');

        if ($this->billing->status($team)->state !== BillingState::Ending || !$subscription instanceof Subscription) {
            $this->refuse('This plan is not ending, so there is nothing to keep.');
        }

        $this->stripe($team, 'resume', fn() => $subscription->resume());

        activity()->performedOn($team)->causedBy($user)->log('plan_resumed');
    }

    public function portalUrl(Team $team): string
    {
        if (!$team->hasStripeId()) {
            $this->refuse('There is no payment method to update yet. Choose a plan first.');
        }

        return (string) $this->stripe($team, 'portal', fn() => $team->billingPortalUrl(route('settings.billing')));
    }

    private function closePendingCheckout(Team $team): void
    {
        $pending = $team->pending_checkout_id;

        if (!is_string($pending) || $pending === '') {
            return;
        }

        try {
            Cashier::stripe()->checkout->sessions->expire($pending);
        } catch (InvalidRequestException) {
            if ($this->checkoutStatus($pending) === 'complete') {
                $this->refuse('A checkout for this team has already gone through. Its plan appears here in a moment.');
            }
        }
    }

    private function checkoutStatus(string $id): ?string
    {
        try {
            return Cashier::stripe()->checkout->sessions->retrieve($id)->status;
        } catch (InvalidRequestException $exception) {
            if ($exception->getStripeCode() === 'resource_missing') {
                return null;
            }

            throw $exception;
        }
    }

    private function stripe(Team $team, string $action, Closure $call): mixed
    {
        try {
            return $call();
        } catch (ApiErrorException $exception) {
            Log::warning('Stripe could not make a billing change.', ['team' => $team->getKey(), 'action' => $action, 'stripe' => $exception->getMessage()]);

            $this->refuse('Stripe could not make that change, so nothing changed. Try again in a minute.');
        }
    }

    private function day(?CarbonImmutable $moment): string
    {
        return $moment?->setTimezone((string) config('app.timezone', 'UTC'))->format('M j') ?? '';
    }

    private function refuse(string $message): never
    {
        throw ValidationException::withMessages(['plan' => $message]);
    }
}
