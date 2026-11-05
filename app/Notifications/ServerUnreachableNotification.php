<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Server;
use App\Services\Panel\Formats;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class ServerUnreachableNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Server $server,
        public readonly string $reason,
        public readonly int $outages = 1,
        public readonly ?CarbonInterface $lastMailed = null,
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
        $since = Formats::absolute($this->server->last_connected_at ?? $this->server->unreachable_since);

        $mail = (new MailMessage)
            ->subject("{$this->server->name} is not answering")
            ->line("Coresmith has not been able to reach {$this->server->name} ({$this->server->ip_address}) over SSH since {$since}.");

        if ($this->outages > 1 && $this->lastMailed !== null) {
            $mail->line("It has stopped answering {$this->outages} times since the last email about it, on " . Formats::absolute($this->lastMailed) . '.');
        }

        return $mail
            ->line($this->reason)
            ->line('Its apps may still be serving. Coresmith checks every minute and emails again when it answers.')
            ->action('Open ' . $this->server->name, route('servers.show', $this->server));
    }
}
