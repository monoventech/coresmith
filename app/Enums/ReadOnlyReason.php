<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Enums;

enum ReadOnlyReason: string
{
    case NoPlan = 'no_plan';
    case TrialEnded = 'trial_ended';
    case PlanEnded = 'plan_ended';
    case PaymentFailed = 'payment_failed';
    case FreePlanEnded = 'free_plan_ended';

    public function title(): string
    {
        return match ($this) {
            self::NoPlan => 'This team has no plan yet, so it is read-only.',
            self::TrialEnded => "This team's trial has ended, so it is read-only.",
            self::PlanEnded => "This team's plan has ended, so it is read-only.",
            self::PaymentFailed => "This team's payments failed, so it is read-only.",
            self::FreePlanEnded => "This team's free plan has ended, so it is read-only.",
        };
    }
}
