<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Events;

use App\Enums\TerminalEndReason;
use App\Services\Panel\Realtime;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

final class TerminalEnded implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(public readonly string $sessionId, public readonly TerminalEndReason $reason) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('terminals.' . $this->sessionId)];
    }

    public function broadcastAs(): string
    {
        return 'terminal.ended';
    }

    /**
     * @return array{reason: string, sentence: string}
     */
    public function broadcastWith(): array
    {
        return ['reason' => $this->reason->value, 'sentence' => $this->reason->sentence()];
    }

    public function broadcastWhen(): bool
    {
        return Realtime::available();
    }
}
