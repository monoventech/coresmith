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

final class CronJobNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public bool $deleteWhenMissingModels = true;

    public function __construct(
        public readonly Application $application,
        public readonly string $job,
        public readonly string $reason,
        public readonly int $moment,
        public readonly ?int $exit,
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
        $when = CarbonImmutable::createFromTimestamp($this->moment, $this->timezone)->format('M j \a\t H:i T');
        $server = $this->application->server?->name ?? 'the server';

        $mail = $this->reason === 'failed'
            ? (new MailMessage)
                ->subject("{$name}: {$this->job} failed")
                ->line("It exited with code {$this->exit} at {$when}. What it printed is in the scheduler log on the app's page.")
                ->line("You'll get one more email when it runs without an error.")
            : (new MailMessage)
                ->subject("{$name}: {$this->job} didn't run")
                ->line("It was due at {$when} and didn't start. Check that cron is running on {$server}.")
                ->line("You'll get one more email when it runs again.");

        return $mail->action('Open ' . $name, route('servers.applications.show', [$this->application->server_id, $this->application]));
    }
}
