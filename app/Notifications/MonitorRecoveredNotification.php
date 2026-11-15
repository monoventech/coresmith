<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\MonitorKind;
use App\Models\Server;
use App\Services\Panel\Formats;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class MonitorRecoveredNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public readonly Server $server, public readonly MonitorKind $kind, public readonly int $minutes) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $thing = match ($this->kind) {
            MonitorKind::Disk => 'disk space',
            MonitorKind::Memory => 'memory',
            MonitorKind::Load => 'load',
            default => 'processor',
        };
        $lasted = match (true) {
            $this->minutes <= 1 => 'a minute',
            $this->minutes < 60 => "{$this->minutes} minutes",
            default => Formats::downFor($this->minutes * 60),
        };

        return (new MailMessage)
            ->subject("{$this->server->name} {$thing} is back to normal")
            ->line("It was over its limit for {$lasted}.")
            ->action('Open ' . $this->server->name, route('servers.show', $this->server));
    }
}
