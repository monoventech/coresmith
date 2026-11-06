<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Notifications;

use App\Jobs\DeployApplication;
use App\Models\Server;
use App\Services\Deployment\ReleasePaths;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class DiskPressureNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public bool $deleteWhenMissingModels = true;

    public function __construct(
        public readonly Server $server,
        public readonly int $percent,
        public readonly bool $critical = false,
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
            ->subject("{$this->server->name} disk is at {$this->percent}%" . ($this->critical ? ', deploys are paused' : ''))
            ->line($this->critical
                ? 'Coresmith stops deploying to a server whose disk is ' . DeployApplication::DISK_ABORT_PERCENT . '% full, so deploys there fail until space is freed.'
                : 'Deploys stop at 95%, so there is still room, but not much.')
            ->line('Pruning keeps the last ' . ReleasePaths::KEEP_RELEASES . ' releases of each app, plus the live one.')
            ->action('Open ' . $this->server->name, route('servers.show', $this->server));
    }
}
