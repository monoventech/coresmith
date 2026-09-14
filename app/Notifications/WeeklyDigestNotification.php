<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class WeeklyDigestNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array{deployed: int, failed: int, rollbacks: int, warnings: int}  $summary
     */
    public function __construct(public readonly array $summary) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $summary = $this->summary;

        return (new MailMessage)
            ->subject('Your week on Coresmith')
            ->line("{$summary['deployed']} " . ($summary['deployed'] === 1 ? 'deploy' : 'deploys') . ' went live and ' . $summary['failed'] . ' failed without reaching anyone.')
            ->line("{$summary['rollbacks']} " . ($summary['rollbacks'] === 1 ? 'rollback' : 'rollbacks') . ' and ' . $summary['warnings'] . ' disk ' . ($summary['warnings'] === 1 ? 'warning' : 'warnings') . '.')
            ->action('Open the fleet', route('dashboard'));
    }
}
