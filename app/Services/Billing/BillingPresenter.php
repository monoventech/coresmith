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
use App\Models\TeamInvoice;
use App\Models\User;
use App\Services\Panel\Formats;
use Carbon\CarbonImmutable;

final class BillingPresenter
{
    private const WARN_DAYS = 3;

    private const INVOICES = 24;

    private const BRANDS = ['visa' => 'Visa', 'mastercard' => 'Mastercard', 'amex' => 'American Express', 'discover' => 'Discover'];

    private const SELF_HOSTING_URL = 'https://github.com/monoventech/coresmith#self-hosting';

    public function __construct(private readonly TeamBilling $billing, private readonly PlanChangeGuard $guard) {}

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
     * @return array<string, mixed>
     */
    public function page(Team $team, User $user, ?string $checkout = null): array
    {
        $status = $this->billing->status($team);
        $canManage = $user->can('manageBilling', $team);
        $trial = $status->state === BillingState::Trialing && $status->plan === Plan::Trial;
        $subscribed = $team->subscription('default')?->valid() === true;
        $invoices = $team->billingInvoices()->where('status', '!=', 'draft');
        $count = $canManage ? $invoices->clone()->count() : 0;
        $card = $canManage && is_string($team->pm_last_four) && $team->pm_last_four !== '';

        return [
            'can_manage' => $canManage,
            'plan' => [
                ...$this->planCard($team, $status),
                'primary' => $canManage ? $this->primary($status) : null,
                'self_host' => $canManage && $status->state !== BillingState::Ending,
            ],
            'payment' => $card ? ['label' => $this->brand((string) $team->pm_type) . ' •••• ' . $team->pm_last_four, 'expires' => $team->pm_expires] : null,
            'portal' => $canManage && $team->stripe_id !== null,
            'payment_note' => match (true) {
                !$canManage => 'Only an owner can see and change the payment method.',
                $card => '',
                $status->state === BillingState::Free => 'No card needed while the plan is free.',
                $team->stripe_id !== null => 'No card shown here yet. Add or update it at Stripe.',
                default => 'No card yet. You add one when you choose a plan.',
            },
            'invoices' => $canManage ? $invoices->limit(self::INVOICES)->get()->map(fn(TeamInvoice $invoice): array => [
                'id' => $invoice->id,
                'period' => $invoice->period_start?->format('M Y') ?? '',
                'number' => (string) $invoice->number,
                'amount' => Formats::cents((int) $invoice->total, (string) $invoice->currency),
                'status' => $this->invoiceStatus($invoice),
                'pdf' => $invoice->pdf_url,
            ])->values()->all() : [],
            'invoice_count' => $count,
            'invoices_note' => match (true) {
                !$canManage => 'Only an owner can see invoices.',
                $count > 0 => null,
                $status->state === BillingState::Free => "No invoices. This team's plan is free of charge.",
                $trial => 'No invoices yet. The first one arrives when the trial ends and a plan starts.',
                default => 'No invoices yet. The first one arrives when you choose a plan.',
            },
            'receipts_to' => $canManage && $team->stripe_id !== null ? $team->stripeEmail() : null,
            'plans' => array_map(fn(Plan $option): array => [
                'key' => $option->value,
                'label' => $option->label(),
                'summary' => $option->summary($status->plan === $option ? $this->paid($team) : null),
                'current' => $status->plan === $option && $status->state !== BillingState::Free,
                'available' => $option->checkoutPrice() !== null,
                'blockers' => $canManage ? $this->guard->blockers($team, $option) : [],
                'notes' => $canManage ? $this->guard->notes($team, $option) : [],
            ], Plan::purchasable()),
            'self_hosting' => [
                'has_subscription' => $subscribed,
                'ends_on' => $subscribed ? $this->dayOrNull($status->renewsAt ?? $status->trialEndsAt) : null,
                'trial_ends_on' => $trial ? $this->dayOrNull($status->trialEndsAt) : null,
                'guide' => self::SELF_HOSTING_URL,
            ],
            'move' => $team->moving_at === null ? null : [
                'to' => (string) $team->moving_to,
                'since' => $team->moving_at->setTimezone((string) config('app.timezone', 'UTC'))->format('M j'),
                'file' => $canManage ? route('settings.billing.move.file') : null,
                'can_cancel' => $canManage,
            ],
            'checkout' => $this->checkout($team, $status, $subscribed, $checkout),
        ];
    }

    public function paletteHint(Team $team): string
    {
        $status = $this->billing->status($team);
        $label = $status->plan?->label() ?? '';

        return match (true) {
            $status->readOnly() => 'read-only, choose a plan',
            $status->state === BillingState::Ending => "{$label} · ends {$this->day($status->endsAt)}",
            $status->state === BillingState::Trialing && $status->plan === Plan::Trial => match ($days = (int) $status->trialDaysLeft()) {
                0 => 'trial · ends today',
                1 => 'trial · ends tomorrow',
                default => "trial · {$days} days left",
            },
            $status->state === BillingState::Trialing => "{$label} · first charge {$this->day($status->trialEndsAt)}",
            $status->state === BillingState::Free => $status->endsAt === null ? "{$label} · free" : "{$label} · free until {$this->day($status->endsAt)}",
            default => "{$label} · {$this->price($team, $status->plan)} / month",
        };
    }

    /**
     * @return array{key: ?string, label: string, badge: array{label: string, tone: string}, line: string, amount: string, usage: string, note: ?string}
     */
    private function planCard(Team $team, BillingStatus $status): array
    {
        $plan = $status->plan;
        $price = $this->price($team, $plan);
        $read = ['label' => 'Read-only', 'tone' => 'destructive'];

        [$label, $badge, $line] = match (true) {
            $status->state === BillingState::Active => [$plan?->label(), ['label' => 'Active', 'tone' => 'accent'], "{$price} per month · renews {$this->day($status->renewsAt)}"],
            $status->state === BillingState::Free => [$plan?->label(), ['label' => 'Free', 'tone' => 'success'], $status->endsAt === null ? 'Free of charge' : "Free until {$this->day($status->endsAt)}"],
            $status->state === BillingState::Trialing && $plan === Plan::Trial => ['Trial', ['label' => 'Trial', 'tone' => 'accent'], 'Pro features on 1 server · trial ends ' . $this->day($status->trialEndsAt)],
            $status->state === BillingState::Trialing => [$plan?->label(), ['label' => 'Trial', 'tone' => 'accent'], "{$price} per month · first charge {$this->day($status->trialEndsAt)}"],
            $status->state === BillingState::PastDue => [$plan?->label(), ['label' => 'Payment failed', 'tone' => 'destructive'], "{$price} per month · payment failed"],
            $status->state === BillingState::Ending => [$plan?->label(), ['label' => 'Ending', 'tone' => 'warning'], "{$price} per month · ends {$this->day($status->endsAt)}"],
            $status->reason === ReadOnlyReason::TrialEnded => ['Trial', $read, "Trial ended {$this->day($status->trialEndsAt)} · this team is read-only"],
            $status->reason === ReadOnlyReason::PlanEnded => ['No plan', $read, "Ended {$this->day($status->endsAt)} · this team is read-only"],
            $status->reason === ReadOnlyReason::PaymentFailed => ['No plan', $read, 'Payments failed · this team is read-only'],
            $status->reason === ReadOnlyReason::FreePlanEnded => ['No plan', $read, "Free plan ended {$this->day($status->endsAt)} · this team is read-only"],
            default => ['No plan', $read, 'No plan yet · this team is read-only'],
        };

        return [
            'key' => $plan?->value,
            'label' => (string) $label,
            'badge' => $badge,
            'line' => $line,
            'amount' => $plan === null || $plan === Plan::Trial || $status->state === BillingState::Free ? '$0' : $price,
            'usage' => $this->usage($team, $status),
            'note' => $status->state === BillingState::Ending
                ? "The plan stays active until {$this->day($status->endsAt)}. After that this team is read-only, and its servers and apps keep running."
                : null,
        ];
    }

    /**
     * @return array{status: string, awaiting: bool, message: string, timeout_message: string}|null
     */
    private function checkout(Team $team, BillingStatus $status, bool $subscribed, ?string $checkout): ?array
    {
        if (!in_array($checkout, ['success', 'cancelled'], true)) {
            return null;
        }

        $onPlan = "You're on {$status->plan?->label()}.";

        return [
            'status' => $checkout,
            'awaiting' => $checkout === 'success' && (!$subscribed || $team->pending_checkout_id !== null),
            'message' => match (true) {
                $checkout === 'cancelled' => 'Checkout was cancelled, so nothing was charged.',
                !$subscribed => 'Checkout finished. The plan appears here as soon as Stripe confirms it.',
                default => $onPlan,
            },
            'timeout_message' => $subscribed ? "{$onPlan} Refresh in a minute to see the card." : 'Stripe has not confirmed the plan yet. Refresh in a minute.',
        ];
    }

    private function primary(BillingStatus $status): ?string
    {
        return match (true) {
            $status->state === BillingState::Free => $status->endsAt === null ? null : 'choose',
            $status->state === BillingState::Ending => 'keep',
            $status->readOnly(), $status->plan === Plan::Trial => 'choose',
            default => 'change',
        };
    }

    private function usage(Team $team, BillingStatus $status): string
    {
        $count = $team->servers()->count();
        $limit = $status->readOnly() ? null : $status->plan?->serverLimit();

        if ($limit === null) {
            return $count . ($count === 1 ? ' server' : ' servers');
        }

        return "{$count} of {$limit}" . ($limit === 1 ? ' server' : ' servers');
    }

    /**
     * @return array{label: string, tone: string}
     */
    private function invoiceStatus(TeamInvoice $invoice): array
    {
        return match (true) {
            $invoice->status === 'paid' => ['label' => 'Paid', 'tone' => 'success'],
            $invoice->status === 'open' && (int) $invoice->attempt_count === 0 => ['label' => 'Due', 'tone' => 'warning'],
            $invoice->status === 'open' => ['label' => 'Failed', 'tone' => 'destructive'],
            $invoice->status === 'void' => ['label' => 'Void', 'tone' => 'outline'],
            default => ['label' => 'Unpaid', 'tone' => 'destructive'],
        };
    }

    private function brand(string $type): string
    {
        return self::BRANDS[$type] ?? ucfirst(str_replace('_', ' ', $type));
    }

    private function price(Team $team, ?Plan $plan): string
    {
        return $this->paid($team) ?? Formats::cents(($plan?->amount() ?? 0) * 100, 'usd');
    }

    private function paid(Team $team): ?string
    {
        $subscription = $team->subscription('default');

        return $subscription?->unit_amount === null ? null : Formats::cents($subscription->unit_amount, (string) $subscription->currency);
    }

    private function dayOrNull(?CarbonImmutable $moment): ?string
    {
        return $moment === null ? null : $this->day($moment);
    }

    private function day(?CarbonImmutable $moment): string
    {
        if ($moment === null) {
            return '';
        }

        $local = $moment->setTimezone((string) config('app.timezone', 'UTC'));

        return $local->format($local->year === CarbonImmutable::now()->year ? 'M j' : 'M j, Y');
    }

    /**
     * @return array{tone: string, title: string, body: string, action: ?string}|null
     */
    private function banner(Team $team, BillingStatus $status, bool $canManage): ?array
    {
        if ($team->moving_at !== null) {
            return $this->bannerOf(
                'warning',
                'This team is moving to your own panel.',
                'Coresmith Cloud has stopped managing its servers, and they keep running. ' . ($canManage ? "Upload the move file on {$team->moving_to} to finish." : 'Ask an owner if it should stay here.'),
                $canManage ? 'See the move' : null,
            );
        }

        if ($status->state === BillingState::Trialing && $status->plan === Plan::Trial && $status->trialEndsAt !== null && ($status->trialDaysLeft() ?? self::WARN_DAYS + 1) <= self::WARN_DAYS) {
            $days = (int) $status->trialDaysLeft();
            $title = match ($days) {
                0 => 'Your trial ends today.',
                1 => 'Your trial ends tomorrow.',
                default => "Your trial ends in {$days} days.",
            };

            return $this->bannerOf('warning', $title, "After {$this->date($status->trialEndsAt)} this team becomes read-only. " . ($canManage ? 'Choose a plan to keep making changes.' : 'Ask an owner to choose a plan.'), $canManage ? 'Choose a plan' : null);
        }

        if ($status->state === BillingState::Free && $status->endsAt !== null && ($days = $this->daysLeft($status->endsAt)) <= self::WARN_DAYS) {
            $title = match ($days) {
                0 => 'Your free plan ends today.',
                1 => 'Your free plan ends tomorrow.',
                default => "Your free plan ends in {$days} days.",
            };

            return $this->bannerOf('warning', $title, "After {$this->date($status->endsAt)} this team becomes read-only. " . ($canManage ? 'Choose a plan to keep making changes.' : 'Ask an owner to choose a plan.'), $canManage ? 'Choose a plan' : null);
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

    private function daysLeft(CarbonImmutable $moment): int
    {
        return max(0, (int) CarbonImmutable::today()->diffInDays($moment->startOfDay(), false));
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
        if ($status->readOnly()) {
            return ($status->reason ?? ReadOnlyReason::NoPlan)->title();
        }

        return $status->allows($feature) ? null : $feature->note();
    }

    private function date(CarbonImmutable $moment): string
    {
        return $moment->setTimezone((string) config('app.timezone', 'UTC'))->format('M j');
    }
}
