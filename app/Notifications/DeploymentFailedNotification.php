<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Deployment;
use App\Services\Deployment\DeploymentPhases;
use App\Services\Panel\DeploymentPresenter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class DeploymentFailedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public readonly Deployment $deployment) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $application = $this->deployment->application;
        $failed = collect(app(DeploymentPhases::class)->states($this->deployment))->firstWhere('state', 'failed');
        $phase = strtolower($failed['label'] ?? 'deploy');
        $reached = DeploymentPresenter::reachedUsers($this->deployment);

        return (new MailMessage)
            ->subject("{$application->name} failed to deploy" . ($reached ? '' : ', nothing changed'))
            ->line("The {$phase} step failed on {$application->server?->name}.")
            ->line($reached
                ? 'It failed after the switch to the new release, so that release is live. Check its processes.'
                : 'The current release kept serving the whole time, so no one saw an error page.')
            ->action('Read the log', route('servers.applications.deployments.show', [$application->server_id, $application, $this->deployment]));
    }
}
