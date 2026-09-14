<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Events;

use App\Http\Controllers\DeploymentController;
use App\Models\Deployment;
use App\Services\Deployment\DeploymentPhases;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class DeploymentUpdated implements ShouldBroadcast
{
    use Dispatchable, SerializesModels;

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
        return DeploymentController::present($this->deployment, app(DeploymentPhases::class));
    }
}
