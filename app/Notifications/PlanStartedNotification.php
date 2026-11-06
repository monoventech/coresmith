<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\Plan;
use App\Models\Team;
use App\Services\Teams\TeamUrls;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class PlanStartedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public readonly Team $team, public readonly Plan $plan) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("{$this->team->name} is on {$this->plan->label()}")
            ->line('Thanks for choosing Coresmith Cloud.')
            ->line('Receipts come from Stripe and are listed on the Billing tab.')
            ->action('Open billing', app(TeamUrls::class)->billing($this->team));
    }
}
