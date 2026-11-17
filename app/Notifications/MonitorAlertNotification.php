<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\MonitorKind;
use App\Models\Server;
use App\Services\Monitors\MonitorLimit;
use App\Services\Teams\TeamLinks;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class MonitorAlertNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public readonly Server $server, public readonly MonitorKind $kind, public readonly float $value, public readonly MonitorLimit $limit, public readonly float $latest) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $name = $this->server->name;
        $minutes = $this->limit->duration === 1 ? 'a minute' : "{$this->limit->duration} minutes";
        $stayed = (int) floor($this->value);
        $current = (int) floor($this->latest);
        $load = number_format($this->value, 2);
        $currentLoad = number_format($this->latest, 2);

        [$subject, $line] = match ($this->kind) {
            MonitorKind::Memory => ["{$name} memory is at {$current}%", "Memory has stayed at {$stayed}% or more for {$minutes}. An app that asks for more is killed and started again."],
            MonitorKind::Load => ["{$name} load is at {$currentLoad} per core", "The load has stayed at {$load} per core or more for {$minutes}, so work is waiting its turn for the processor."],
            default => ["{$name} processor is {$current}% busy", $stayed >= 100
                ? "The processor has been 100% busy for {$minutes}, so apps on it may answer slowly."
                : "The processor has stayed {$stayed}% busy or more for {$minutes}, so apps on it may answer slowly."],
        };

        return (new MailMessage)
            ->subject($subject)
            ->line($line)
            ->line("Check which process is using it on the server. You'll get one more email when it's back to normal.")
            ->action('Open ' . $name, app(TeamLinks::class)->server($this->server));
    }
}
