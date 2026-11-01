<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Team;
use App\Services\Teams\TeamUrls;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class TrialEndingNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Team $team, public readonly int $days, public readonly CarbonImmutable $endsAt) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $when = match ($this->days) {
            0 => 'today',
            1 => 'in 1 day',
            default => "in {$this->days} days",
        };
        $trialDays = (int) config('coresmith.billing.trial_days', 14);
        $ends = $this->endsAt->setTimezone((string) config('app.timezone', 'UTC'))->format('M j');

        return (new MailMessage)
            ->subject("Your Coresmith trial ends {$when}")
            ->line("{$this->team->name} is on a {$trialDays}-day trial of Pro. It ends on {$ends}.")
            ->line('After that the team becomes read-only: servers and apps keep running, but nothing can change until you choose a plan.')
            ->action('Choose a plan', app(TeamUrls::class)->billing($this->team));
    }
}
