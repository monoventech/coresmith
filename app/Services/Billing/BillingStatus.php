<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\BillingState;
use App\Enums\Plan;
use App\Enums\PlanFeature;
use App\Enums\ReadOnlyReason;
use Carbon\CarbonImmutable;

final readonly class BillingStatus
{
    public function __construct(
        public BillingState $state,
        public ?Plan $plan,
        public ?ReadOnlyReason $reason = null,
        public ?CarbonImmutable $trialEndsAt = null,
        public ?CarbonImmutable $endsAt = null,
        public ?CarbonImmutable $renewsAt = null,
    ) {}

    public function readOnly(): bool
    {
        return $this->state === BillingState::ReadOnly;
    }

    public function allows(PlanFeature $feature): bool
    {
        return !$this->readOnly() && $this->plan?->includes($feature) === true;
    }

    public function serverLimit(): ?int
    {
        return $this->readOnly() ? 0 : $this->plan?->serverLimit();
    }

    public function memberLimit(): ?int
    {
        return $this->readOnly() ? null : $this->plan?->memberLimit();
    }

    public function trialDaysLeft(): ?int
    {
        if ($this->trialEndsAt === null) {
            return null;
        }

        return max(0, (int) CarbonImmutable::today()->diffInDays($this->trialEndsAt->startOfDay(), false));
    }
}
