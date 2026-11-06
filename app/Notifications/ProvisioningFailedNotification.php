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

final class ProvisioningFailedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public bool $deleteWhenMissingModels = true;

    public function __construct(
        public readonly Server $server,
        public readonly int $phase,
        public readonly string $phaseName,
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
            ->subject("Provisioning stopped on {$this->server->name}")
            ->line("Phase {$this->phase} of 8, {$this->phaseName}, failed, so {$this->server->name} is not ready yet.")
            ->line('Fix what the log shows, then retry from that phase on the server page.')
            ->action('Open ' . $this->server->name, route('servers.show', $this->server));
    }
}
