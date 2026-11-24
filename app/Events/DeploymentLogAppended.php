<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Events;

use App\Services\Panel\Realtime;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

final class DeploymentLogAppended implements ShouldBroadcast
{
    use Dispatchable;

    public const PIECE_BYTES = 8_000;

    private const HEADER_BYTES = 1_000;

    public function __construct(public readonly string $deploymentId, public readonly int $offset, public readonly string $text) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('deployments.' . $this->deploymentId)];
    }

    public function broadcastAs(): string
    {
        return 'deployment.log';
    }

    /**
     * @return array{offset: int, text: string}
     */
    public function broadcastWith(): array
    {
        return ['offset' => $this->offset, 'text' => $this->text];
    }

    public function broadcastWhen(): bool
    {
        return Realtime::available();
    }

    public function fits(): bool
    {
        $request = (string) json_encode([
            'name' => $this->broadcastAs(),
            'data' => (string) json_encode($this->broadcastWith()),
            'channels' => array_map(fn(PrivateChannel $channel): string => $channel->name, $this->broadcastOn()),
        ]);

        return strlen($request) + self::HEADER_BYTES <= (int) config('reverb.servers.reverb.max_request_size', 10_000);
    }
}
