<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Panel;

use App\Enums\DeploymentStatus;
use App\Models\Deployment;

final class DeploymentPresenter
{
    /**
     * @return array{key: string, label: string, tone: string, live: bool}
     */
    public static function state(Deployment $deployment): array
    {
        return match ($deployment->status) {
            DeploymentStatus::Running => ['key' => 'running', 'label' => 'Running', 'tone' => 'accent', 'live' => true],
            DeploymentStatus::Succeeded => ['key' => 'deployed', 'label' => 'Deployed', 'tone' => 'success', 'live' => false],
            DeploymentStatus::Failed => self::reachedUsers($deployment)
                ? ['key' => 'failed', 'label' => 'Failed', 'tone' => 'destructive', 'live' => false]
                : ['key' => 'failed', 'label' => 'Failed, no impact', 'tone' => 'destructive', 'live' => false],
            DeploymentStatus::Cancelled => ['key' => 'cancelled', 'label' => 'Cancelled', 'tone' => 'default', 'live' => false],
            default => ['key' => 'queued', 'label' => 'Queued', 'tone' => 'default', 'live' => false],
        };
    }

    public static function reachedUsers(Deployment $deployment): bool
    {
        return preg_match('/^\[\d{2}:\d{2}:\d{2}\] swap:/m', (string) $deployment->log) === 1;
    }

    /**
     * @return array<string, mixed>
     */
    public static function row(Deployment $deployment): array
    {
        $application = $deployment->application;

        return [
            'id' => $deployment->getKey(),
            'application' => (string) $application?->name,
            'server' => (string) $application?->server?->name,
            'release' => Formats::release($deployment->release),
            'branch' => $deployment->branch ?? $application?->repository_branch,
            'when' => Formats::when($deployment->started_at ?? $deployment->created_at),
            'full' => Formats::absolute($deployment->started_at ?? $deployment->created_at),
            'duration' => $deployment->status === DeploymentStatus::Running ? null : Formats::duration($deployment->started_at, $deployment->finished_at),
            'state' => self::state($deployment),
            'href' => $application === null ? null : route('servers.applications.deployments.show', [$application->server_id, $application, $deployment]),
        ];
    }
}
