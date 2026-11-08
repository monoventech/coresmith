<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\BillingState;
use App\Enums\Edition;
use App\Enums\Plan;
use App\Enums\ReadOnlyReason;
use App\Models\Subscription;
use App\Models\Team;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\Log;

final class TeamBilling
{
    private const PAYMENT_FAILED = ['unpaid', 'incomplete', 'incomplete_expired'];

    public function status(Team $team): BillingStatus
    {
        if (Edition::current()->isSelfHosted()) {
            return new BillingStatus(BillingState::Unlimited, Plan::SelfHosted);
        }

        $subscription = $team->subscription('default');

        if ($subscription instanceof Subscription && $subscription->valid()) {
            $plan = $this->plan($team, $subscription);

            return match (true) {
                $subscription->onGracePeriod() => new BillingStatus(BillingState::Ending, $plan, endsAt: $this->moment($subscription->ends_at)),
                $subscription->pastDue() => new BillingStatus(BillingState::PastDue, $plan, renewsAt: $this->moment($subscription->renews_at)),
                $subscription->onTrial() => new BillingStatus(BillingState::Trialing, $plan, trialEndsAt: $this->moment($subscription->trial_ends_at)),
                default => new BillingStatus(BillingState::Active, $plan, renewsAt: $this->moment($subscription->renews_at)),
            };
        }

        $free = $team->free_plan;
        $freeUntil = $this->moment($team->free_until);

        if ($free instanceof Plan && ($freeUntil === null || $freeUntil->isFuture())) {
            return new BillingStatus(BillingState::Free, $free, endsAt: $freeUntil);
        }

        if ($team->onGenericTrial()) {
            return new BillingStatus(BillingState::Trialing, Plan::Trial, trialEndsAt: $this->moment($team->trial_ends_at));
        }

        $freeEnded = $free instanceof Plan ? $freeUntil : null;

        if ($subscription !== null && ($freeEnded === null || $subscription->ends_at === null || !$freeEnded->greaterThan($subscription->ends_at))) {
            return in_array($subscription->stripe_status, self::PAYMENT_FAILED, true)
                ? new BillingStatus(BillingState::ReadOnly, null, ReadOnlyReason::PaymentFailed)
                : new BillingStatus(BillingState::ReadOnly, null, ReadOnlyReason::PlanEnded, endsAt: $this->moment($subscription->ends_at));
        }

        if ($freeEnded !== null) {
            return new BillingStatus(BillingState::ReadOnly, null, ReadOnlyReason::FreePlanEnded, endsAt: $freeEnded);
        }

        if ($team->trial_ends_at !== null && $team->trial_ends_at->isPast()) {
            return new BillingStatus(BillingState::ReadOnly, null, ReadOnlyReason::TrialEnded, trialEndsAt: $this->moment($team->trial_ends_at));
        }

        return new BillingStatus(BillingState::ReadOnly, null, ReadOnlyReason::NoPlan);
    }

    private function plan(Team $team, Subscription $subscription): Plan
    {
        $plan = Plan::fromPriceId($subscription->stripe_price);

        if ($plan === null) {
            Log::warning('A subscription is on a price Coresmith does not know.', ['team' => $team->getKey(), 'price' => $subscription->stripe_price]);
        }

        return $plan ?? Plan::Starter;
    }

    private function moment(?DateTimeInterface $moment): ?CarbonImmutable
    {
        return $moment === null ? null : CarbonImmutable::instance($moment);
    }
}
