<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\AppSize;
use App\Models\Application;
use App\Services\Panel\Formats;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class ApplicationOomKilledNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public bool $deleteWhenMissingModels = true;

    public function __construct(
        public readonly Application $application,
        public readonly string $process,
        public readonly int $limitMb,
        public readonly CarbonImmutable $at,
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
        $application = $this->application;
        $size = ucfirst((AppSize::tryFrom((string) $application->app_size) ?? AppSize::Small)->value);

        return (new MailMessage)
            ->subject("{$application->name} was killed for memory")
            ->line("The {$this->process} process on {$application->server?->name} went over its {$this->limitMb} MB memory limit at " . Formats::absolute($this->at) . ', and systemd started it again.')
            ->line("If it keeps happening, the app needs more memory than the {$size} size gives it.")
            ->action('Open ' . $application->name, route('servers.applications.show', [$application->server_id, $application]));
    }
}
