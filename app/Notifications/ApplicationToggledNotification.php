<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Application;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class ApplicationToggledNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Application $application,
        public readonly string $change,
        public readonly ?string $actor,
        public readonly bool $withoutDeployment = true,
    ) {}

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
            ->subject("{$this->change} · {$this->application->name}")
            ->line(($this->actor ?? 'Someone') . ' made the change from the panel.' . ($this->withoutDeployment ? ' No deployment was needed.' : ''))
            ->action('Open ' . $this->application->name, route('servers.applications.show', [$this->application->server_id, $this->application]));
    }
}
