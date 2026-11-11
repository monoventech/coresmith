<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Notifications;

use App\Models\ManagedDatabase;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class DatabaseRestoreNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public bool $deleteWhenMissingModels = true;

    public function __construct(
        public readonly ManagedDatabase $database,
        public readonly bool $succeeded,
        public readonly string $when,
        public readonly ?string $error = null,
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
        $name = (string) $this->database->name;
        $server = $this->database->server;

        return (new MailMessage)
            ->subject($this->succeeded ? "Restored {$name} from {$this->when}" : "Restoring {$name} failed")
            ->line($this->succeeded ? "{$name} on {$server?->name} now holds the backup from {$this->when}." : (string) $this->error)
            ->action("Open {$name}", route('servers.databases.show', [$this->database->server_id, $this->database]));
    }
}
