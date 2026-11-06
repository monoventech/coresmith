<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Server;
use App\Services\Cloud\Tasks\CloudTask;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class CloudTaskFailedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Server $server,
        public readonly string $type,
        public readonly string $reason,
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
        $name = $this->server->name;
        $provider = $this->server->cloudProvider?->type->label() ?? 'its provider';

        return (new MailMessage)
            ->subject(match ($this->type) {
                CloudTask::CREATE => "Creating {$name} at {$provider} failed",
                CloudTask::DESTROY => "Destroying {$name} at {$provider} failed",
                CloudTask::RESIZE => "Resizing {$name} at {$provider} failed",
                default => "Coresmith's work on {$name} at {$provider} failed",
            })
            ->line($this->reason)
            ->action("Open {$name}", route('servers.show', $this->server));
    }
}
