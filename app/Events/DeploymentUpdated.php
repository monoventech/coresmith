<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Events;

use App\Http\Controllers\DeploymentController;
use App\Models\Deployment;
use App\Services\Deployment\DeploymentPhases;
use App\Services\Panel\Realtime;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class DeploymentUpdated implements ShouldBroadcast
{
    use Dispatchable, SerializesModels;

    private const HEADER_BYTES = 1_000;

    public function __construct(public readonly Deployment $deployment) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('deployments.' . $this->deployment->getKey())];
    }

    public function broadcastAs(): string
    {
        return 'deployment.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $payload = DeploymentController::present($this->deployment, app(DeploymentPhases::class));

        if ($this->requestBytes($payload) + self::HEADER_BYTES > (int) config('reverb.servers.reverb.max_request_size', 10_000)) {
            $payload['log'] = null;
        }

        return $payload;
    }

    public function broadcastWhen(): bool
    {
        return Realtime::available();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function requestBytes(array $payload): int
    {
        return strlen((string) json_encode([
            'name' => $this->broadcastAs(),
            'data' => (string) json_encode($payload),
            'channels' => array_map(fn(PrivateChannel $channel): string => $channel->name, $this->broadcastOn()),
        ]));
    }
}
