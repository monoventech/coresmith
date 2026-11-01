<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Team;
use App\Services\Teams\TeamUrls;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class PlanEndedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Team $team) {}

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
            ->subject("{$this->team->name}'s Coresmith plan has ended")
            ->line('The team is read-only now. Servers and apps keep running and you can still see everything.')
            ->line('Choose a plan to make changes again.')
            ->action('Choose a plan', app(TeamUrls::class)->billing($this->team));
    }
}
