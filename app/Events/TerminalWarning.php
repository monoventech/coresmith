<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Events;

use App\Services\Panel\Realtime;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

final class TerminalWarning implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(public readonly string $sessionId, public readonly string $reason, public readonly int $endsAt) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('terminals.' . $this->sessionId)];
    }

    public function broadcastAs(): string
    {
        return 'terminal.warning';
    }

    /**
     * @return array{reason: string, ends_at: int}
     */
    public function broadcastWith(): array
    {
        return ['reason' => $this->reason, 'ends_at' => $this->endsAt];
    }

    public function broadcastWhen(): bool
    {
        return Realtime::available();
    }
}
