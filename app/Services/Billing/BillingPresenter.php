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
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonImmutable;

final class BillingPresenter
{
    private const WARN_DAYS = 3;

    public function __construct(private readonly TeamBilling $billing) {}

    /**
     * @return array<string, mixed>
     */
    public function summary(Team $team, User $user): array
    {
        $status = $this->billing->status($team);
        $canManage = $user->can('manageBilling', $team);
        $memberLimit = $status->memberLimit();

        return [
            'plan' => $status->plan?->value,
            'label' => $status->plan?->label(),
            'state' => $status->state->value,
            'read_only' => $status->readOnly(),
            'can_manage' => $canManage,
            'href' => route('settings.billing'),
            'banner' => $this->banner($team, $status, $canManage),
            'notes' => [
                'members' => $memberLimit !== null && $team->members()->count() >= $memberLimit ? "{$status->plan?->label()} is for one person." : null,
                'custom_domain' => $this->note($status, PlanFeature::CustomDomain),
                'single_sign_on' => $this->note($status, PlanFeature::SingleSignOn),
                'offsite_backups' => $this->note($status, PlanFeature::OffsiteBackups),
                'audit_log_export' => $this->note($status, PlanFeature::AuditLogExport),
            ],
        ];
    }

    /**
     * @return array{tone: string, title: string, body: string, action: ?string}|null
     */
    private function banner(Team $team, BillingStatus $status, bool $canManage): ?array
    {
        if ($status->state === BillingState::Trialing && $status->plan === Plan::Trial && $status->trialEndsAt !== null && ($status->trialDaysLeft() ?? self::WARN_DAYS + 1) <= self::WARN_DAYS) {
            $days = (int) $status->trialDaysLeft();
            $title = match ($days) {
                0 => 'Your trial ends today.',
                1 => 'Your trial ends tomorrow.',
                default => "Your trial ends in {$days} days.",
            };

            return $this->bannerOf('warning', $title, "After {$this->date($status->trialEndsAt)} this team becomes read-only. " . ($canManage ? 'Choose a plan to keep making changes.' : 'Ask an owner to choose a plan.'), $canManage ? 'Choose a plan' : null);
        }

        if ($status->state === BillingState::PastDue) {
            $next = $team->billingInvoices()->where('status', 'open')->whereNotNull('next_attempt_at')->latest('next_attempt_at')->value('next_attempt_at');
            $retry = $next === null ? '' : 'Stripe tries again on ' . $this->date(CarbonImmutable::parse($next)) . '. ';

            return $this->bannerOf('warning', 'The last payment failed.', $retry . ($canManage ? 'Update the card so this team keeps working.' : 'Ask an owner to update the card.'), $canManage ? 'Update payment method' : null);
        }

        if ($status->readOnly()) {
            $failed = $status->reason === ReadOnlyReason::PaymentFailed;
            $ask = $failed ? ' Ask an owner to update the payment method.' : ' Ask an owner to choose a plan.';

            return $this->bannerOf(
                'destructive',
                ($status->reason ?? ReadOnlyReason::NoPlan)->title(),
                'Servers and apps keep running, and everything stays visible.' . ($canManage ? '' : $ask),
                $canManage ? ($failed ? 'Update payment method' : 'Choose a plan') : null,
            );
        }

        return null;
    }

    /**
     * @return array{tone: string, title: string, body: string, action: ?string}
     */
    private function bannerOf(string $tone, string $title, string $body, ?string $action): array
    {
        return ['tone' => $tone, 'title' => $title, 'body' => $body, 'action' => $action];
    }

    private function note(BillingStatus $status, PlanFeature $feature): ?string
    {
        return $status->allows($feature) ? null : $feature->note();
    }

    private function date(CarbonImmutable $moment): string
    {
        return $moment->setTimezone((string) config('app.timezone', 'UTC'))->format('M j');
    }
}
