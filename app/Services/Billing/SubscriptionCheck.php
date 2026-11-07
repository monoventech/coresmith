<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\Edition;
use App\Exceptions\PlanRefusal;
use App\Models\Subscription;
use App\Models\Team;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\InvalidRequestException;

final class SubscriptionCheck
{
    public const LIVE_STATUSES = ['active', 'trialing', 'past_due'];

    public function confirm(Team $team): void
    {
        if (Edition::current()->isSelfHosted()) {
            return;
        }

        $subscription = $team->subscription('default');

        if (!$subscription instanceof Subscription || !$subscription->valid()) {
            return;
        }

        try {
            $stripe = $subscription->asStripeSubscription();
        } catch (InvalidRequestException $exception) {
            if ($exception->getStripeCode() !== 'resource_missing') {
                $this->unreachable($team);

                return;
            }

            $subscription->forceFill(['synced_at' => CarbonImmutable::now()])->markAsCanceled();

            throw PlanRefusal::inactive();
        } catch (ApiErrorException) {
            $this->unreachable($team);

            return;
        }

        if (in_array($stripe->status, self::LIVE_STATUSES, true)) {
            return;
        }

        $ended = $stripe->ended_at ?? $stripe->canceled_at;

        $subscription->forceFill([
            'stripe_status' => $stripe->status,
            'ends_at' => is_int($ended) ? CarbonImmutable::createFromTimestamp($ended) : CarbonImmutable::now(),
            'synced_at' => CarbonImmutable::now(),
        ])->save();

        throw PlanRefusal::inactive();
    }

    private function unreachable(Team $team): void
    {
        Log::warning('Stripe could not be reached for the subscription check.', ['team' => $team->getKey()]);
    }
}
