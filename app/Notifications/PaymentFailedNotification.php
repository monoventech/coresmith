<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\Plan;
use App\Models\Team;
use App\Services\Teams\TeamUrls;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class PaymentFailedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public readonly Team $team, public readonly ?Plan $plan, public readonly ?CarbonImmutable $nextAttempt) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $retry = $this->nextAttempt === null
            ? 'It will not try again on its own.'
            : 'It tries again on ' . $this->nextAttempt->setTimezone((string) config('app.timezone', 'UTC'))->format('M j') . '.';

        return (new MailMessage)
            ->subject("Payment failed for {$this->team->name}")
            ->line("Stripe could not charge the card for {$this->team->name}'s " . ($this->plan?->label() ?? 'Coresmith Cloud') . ' plan.')
            ->line($retry)
            ->line('Update the card to keep the team working.')
            ->action('Update payment method', app(TeamUrls::class)->billing($this->team));
    }
}
