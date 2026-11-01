<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\BillingState;
use App\Enums\Edition;
use App\Enums\Plan;
use App\Models\Team;
use App\Notifications\TrialEndingNotification;
use App\Services\Billing\TeamBilling;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

final class TrialRemindersCommand extends Command
{
    public const DAYS_AHEAD = 7;

    protected $signature = 'coresmith:trial-reminders';

    protected $description = 'Remind owners a week before a Coresmith Cloud trial ends';

    public function handle(TeamBilling $billing): int
    {
        if (Edition::current()->isSelfHosted()) {
            $this->info('Trial reminders are part of Coresmith Cloud.');

            return self::SUCCESS;
        }

        $reminded = 0;

        Team::query()
            ->onGenericTrial()
            ->where('trial_ends_at', '<=', now()->addDays(self::DAYS_AHEAD))
            ->whereNull('trial_reminded_at')
            ->lazyById()
            ->each(function (Team $team) use ($billing, &$reminded): void {
                $status = $billing->status($team);

                if ($status->state !== BillingState::Trialing || $status->plan !== Plan::Trial || $status->trialEndsAt === null) {
                    return;
                }

                Notification::send($team->owners()->get(), new TrialEndingNotification($team, (int) $status->trialDaysLeft(), $status->trialEndsAt));
                $team->forceFill(['trial_reminded_at' => now()])->save();
                $reminded++;
            });

        $this->info($reminded === 1 ? 'Reminded 1 team.' : "Reminded {$reminded} teams.");

        return self::SUCCESS;
    }
}
