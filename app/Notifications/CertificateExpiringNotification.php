<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Application;
use App\Services\Server\CertificateCheck;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class CertificateExpiringNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public bool $deleteWhenMissingModels = true;

    public function __construct(
        public readonly Application $application,
        public readonly string $domain,
        public readonly int $days,
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
        $server = $this->application->server;

        return (new MailMessage)
            ->subject("The certificate for {$this->domain} " . CertificateCheck::expiry($this->days))
            ->line('Caddy on ' . $server?->name . ' renews certificates well before they expire, so this one could not be renewed.')
            ->line("Check that {$this->domain} still points at {$server?->ip_address} and that ports 80 and 443 are open.")
            ->action('Open ' . $this->application->name, route('servers.applications.show', [$this->application->server_id, $this->application]));
    }
}
