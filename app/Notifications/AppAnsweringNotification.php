<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Application;
use App\Services\Panel\Formats;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class AppAnsweringNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public readonly Application $application, public readonly int $minutes) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $name = $this->application->name;
        $lasted = $this->minutes < 60 ? "{$this->minutes} minutes" : Formats::downFor($this->minutes * 60);

        return (new MailMessage)
            ->subject("{$name} is answering again")
            ->line("It was failing checks for about {$lasted}.")
            ->action('Open ' . $name, route('servers.applications.show', [$this->application->server_id, $this->application]));
    }
}
