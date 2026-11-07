<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Server;
use App\Services\Panel\Formats;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class ServerProvisionedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public readonly Server $server, public readonly int $durationSeconds) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $finished = CarbonImmutable::now();
        $took = Formats::duration($finished->subSeconds($this->durationSeconds), $finished);

        return (new MailMessage)
            ->subject("{$this->server->name} is ready")
            ->line("Provisioned and hardened in {$took}. All eight phases passed.")
            ->line('Add an app to deploy to it.')
            ->action('Open ' . $this->server->name, route('servers.show', $this->server));
    }
}
