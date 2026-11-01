<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Events;

use App\Models\Application;
use App\Models\Deployment;
use App\Models\Server;
use App\Services\Panel\Realtime;
use Closure;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Throwable;

final class FleetChanged implements ShouldBroadcast
{
    use Dispatchable;

    public function __construct(
        public readonly string $teamId,
        public readonly string $kind,
        public readonly string $subjectId,
    ) {}

    public static function forServer(Server $server): void
    {
        self::ping(fn(): array => [$server->team_id, 'server', $server->getKey()]);
    }

    public static function forApplication(Application $application): void
    {
        self::ping(fn(): array => [$application->team_id, 'application', $application->getKey()]);
    }

    public static function forDeployment(Deployment $deployment): void
    {
        self::ping(fn(): array => [$deployment->application?->team_id, 'deployment', $deployment->getKey()]);
    }

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('teams.' . $this->teamId)];
    }

    public function broadcastAs(): string
    {
        return 'fleet.changed';
    }

    /**
     * @return array<string, string>
     */
    public function broadcastWith(): array
    {
        return ['kind' => $this->kind, 'id' => $this->subjectId];
    }

    public function broadcastWhen(): bool
    {
        return Realtime::available();
    }

    private static function ping(Closure $subject): void
    {
        try {
            [$team, $kind, $id] = $subject();

            if (blank($team)) {
                return;
            }

            self::dispatch((string) $team, $kind, (string) $id);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
