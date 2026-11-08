<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Notifications;

use App\Services\Updates\UpdateStatus;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class PanelUpdateFailedNotification extends Notification
{
    /**
     * @param  array<string, mixed>  $status
     */
    public function __construct(public readonly array $status) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $to = (string) ($this->status['to']['version'] ?? '');
        $from = (string) ($this->status['from']['version'] ?? '');
        $message = (string) ($this->status['message'] ?? '');
        $step = UpdateStatus::STEPS[(string) ($this->status['failed_step'] ?? '')] ?? 'updating';

        $mail = match ($this->status['state'] ?? null) {
            UpdateStatus::ROLLED_BACK => (new MailMessage)
                ->subject("Coresmith couldn't update to v{$to}")
                ->line('The update failed while ' . lcfirst($step) . ", so Coresmith rolled back to v{$from} and is running as before.")
                ->line($message),
            UpdateStatus::FAILED => (new MailMessage)
                ->subject('Coresmith needs attention after a failed update')
                ->line("The update to v{$to} failed and the rollback didn't finish.")
                ->line($message),
            default => (new MailMessage)
                ->subject("Tonight's update to v{$to} didn't run")
                ->line($message),
        };

        return $mail->action('Read the log', route('settings.general'));
    }
}
