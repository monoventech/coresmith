<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Server;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class MemoryPressureNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Server $server, public readonly int $percent) {}

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
            ->subject("{$this->server->name} memory is at {$this->percent}%")
            ->line("Memory has stayed at {$this->percent}% or more for three minutes. An app that asks for more is killed and started again.")
            ->line('Check which process is using it on the server.')
            ->action('Open ' . $this->server->name, route('servers.show', $this->server));
    }
}
