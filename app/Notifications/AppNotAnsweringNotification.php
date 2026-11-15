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

final class AppNotAnsweringNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public readonly Application $application, public readonly string $domain, public readonly ?int $status, public readonly int $checks) {}

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
        $times = match ($this->checks) {
            2 => 'two',
            3 => 'three',
            default => (string) $this->checks,
        };
        $what = $this->status === null ? "didn't answer" : "answered with {$this->status}";

        return (new MailMessage)
            ->subject("{$name} isn't answering")
            ->line("{$this->domain} {$what} {$times} checks in a row, 5 minutes apart.")
            ->line("The live log on the app's page shows what it printed. You'll get one more email when it answers again.")
            ->action('Open ' . $name, route('servers.applications.show', [$this->application->server_id, $this->application]));
    }
}
