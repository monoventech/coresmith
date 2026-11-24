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

final class TerminalOutput implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(public readonly string $sessionId, public readonly int $seq, public readonly string $bytes) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('terminals.' . $this->sessionId)];
    }

    public function broadcastAs(): string
    {
        return 'terminal.output';
    }

    /**
     * @return array{seq: int, data: string}
     */
    public function broadcastWith(): array
    {
        return ['seq' => $this->seq, 'data' => base64_encode($this->bytes)];
    }

    public function broadcastWhen(): bool
    {
        return Realtime::available();
    }
}
