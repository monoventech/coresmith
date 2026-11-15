<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Application;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class CronJobRecoveredNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public bool $deleteWhenMissingModels = true;

    public function __construct(
        public readonly Application $application,
        public readonly string $job,
        public readonly int $started,
        public readonly int $seconds,
        public readonly string $timezone,
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
        $name = $this->application->name;
        $when = CarbonImmutable::createFromTimestamp($this->started, $this->timezone)->format('M j \a\t H:i T');
        $took = match (true) {
            $this->seconds < 1 => 'under a second',
            $this->seconds === 1 => 'a second',
            $this->seconds < 120 => "{$this->seconds} seconds",
            default => intdiv($this->seconds, 60) . ' minutes',
        };

        return (new MailMessage)
            ->subject("{$name}: {$this->job} ran without an error")
            ->line("It ran on {$when} and took {$took}.")
            ->action('Open ' . $name, route('servers.applications.show', [$this->application->server_id, $this->application]));
    }
}
