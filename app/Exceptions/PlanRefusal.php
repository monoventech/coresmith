<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\Plan;
use App\Enums\PlanFeature;
use App\Enums\ReadOnlyReason;
use App\Services\Billing\BillingStatus;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

final class PlanRefusal extends RuntimeException
{
    public function __construct(string $message, public readonly string $reason, public readonly int $status)
    {
        parent::__construct($message);
    }

    public static function readOnly(BillingStatus $status, bool $canManage): self
    {
        $failed = $status->reason === ReadOnlyReason::PaymentFailed;
        $fix = match (true) {
            $failed && $canManage => 'Update the payment method in Billing settings to make changes again.',
            $failed => 'Ask an owner to update the payment method.',
            $canManage => 'Choose a plan in Billing settings to make changes again.',
            default => 'Ask an owner to choose a plan.',
        };

        return new self(($status->reason ?? ReadOnlyReason::NoPlan)->title() . " Servers and apps keep running. {$fix}", 'read_only', 402);
    }

    public static function servers(Plan $plan, int $count, bool $canManage): self
    {
        $message = $plan === Plan::Trial
            ? 'The trial includes 1 server. ' . ($canManage ? 'Choose a plan to add more.' : 'Ask an owner to choose a plan.')
            : "{$plan->label()} includes {$plan->serverLimit()} servers and this team has {$count}. " . ($canManage ? 'Choose Pro or Business for unlimited servers.' : 'Ask an owner to change the plan.');

        return new self($message, 'server_limit', 403);
    }

    public static function members(Plan $plan, bool $canManage): self
    {
        return new self("{$plan->label()} is for one person. " . ($canManage ? 'Choose Pro or Business to add teammates.' : 'Ask an owner to change the plan.'), 'member_limit', 403);
    }

    public static function feature(PlanFeature $feature, bool $canManage): self
    {
        return new self($feature->note() . ' ' . ($canManage ? 'Change the plan in Billing settings.' : 'Ask an owner to change the plan.'), 'feature', 403);
    }

    public static function inactive(): self
    {
        return new self("Stripe says this team's plan is no longer active, so nothing was added. Open Billing settings to see why.", 'inactive', 402);
    }

    public function render(Request $request): Response
    {
        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json(['message' => $this->getMessage(), 'reason' => $this->reason], $this->status);
        }

        return back()->with('error', $this->getMessage());
    }
}
