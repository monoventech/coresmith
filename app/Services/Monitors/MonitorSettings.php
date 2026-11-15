<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Monitors;

use App\Enums\MonitorKind;
use App\Enums\PlanFeature;
use App\Models\Monitor;
use App\Models\Server;
use App\Models\ServerMonitor;
use App\Models\Team;
use App\Models\User;
use App\Services\Billing\TeamBilling;

final class MonitorSettings
{
    public const SERVER_KINDS = [MonitorKind::Disk, MonitorKind::Memory, MonitorKind::Cpu, MonitorKind::Load];

    public function __construct(private readonly TeamBilling $billing) {}

    public function allowsLimits(Team $team): bool
    {
        return $this->billing->status($team)->allows(PlanFeature::Monitors);
    }

    /**
     * @return array{canChange: bool, plan: bool, monitors: list<array<string, mixed>>, overrides: list<array<string, mixed>>, servers: list<array{id: string, name: string}>, serverKinds: list<string>, footnote: string}
     */
    public function forTeam(Team $team, User $user): array
    {
        $plan = $this->allowsLimits($team);
        $rows = Monitor::query()->where('team_id', $team->getKey())->get()->keyBy(fn(Monitor $monitor): string => $monitor->kind->value);
        $servers = $team->servers()->orderBy('name')->get(['id', 'name']);
        $names = $servers->pluck('name', 'id');
        $order = array_map(fn(MonitorKind $kind): string => $kind->value, MonitorKind::cases());

        $overrides = ServerMonitor::query()
            ->where('team_id', $team->getKey())
            ->whereIn('server_id', $servers->modelKeys())
            ->get()
            ->sortBy(fn(ServerMonitor $override): string => $names[$override->server_id] . ':' . array_search($override->kind->value, $order, true))
            ->map(fn(ServerMonitor $override): array => [
                'kind' => $override->kind->value,
                'server' => ['id' => $override->server_id, 'name' => $names[$override->server_id]],
                'rule' => MonitorChoices::serverRule($override->kind, $override->threshold, $override->duration),
                'threshold' => $override->threshold,
                'duration' => $override->duration,
            ])
            ->values()
            ->all();

        return [
            'canChange' => $user->can('update', $team),
            'plan' => $plan,
            'monitors' => array_map(function (MonitorKind $kind) use ($rows, $plan): array {
                $row = $rows[$kind->value] ?? null;
                $threshold = $plan && $row !== null ? $row->threshold : $kind->defaultThreshold();
                $duration = $plan && $row !== null ? $row->duration : $kind->defaultDuration();

                return [
                    'kind' => $kind->value,
                    'name' => MonitorChoices::NAMES[$kind->value],
                    'rule' => MonitorChoices::rule($kind, $threshold, $duration),
                    'enabled' => $row?->enabled ?? true,
                    'threshold' => $threshold,
                    'duration' => $duration,
                    'levels' => array_map(fn(float $level): array => ['value' => $level, 'label' => MonitorChoices::levelLabel($kind, $level)], MonitorChoices::levels($kind)),
                    'durations' => array_map(fn(int $minutes): array => ['value' => $minutes, 'label' => MonitorChoices::durationLabel($kind, $minutes)], MonitorChoices::durations($kind)),
                ];
            }, MonitorKind::cases()),
            'overrides' => $overrides,
            'servers' => $servers->map(fn(Server $server): array => ['id' => (string) $server->getKey(), 'name' => (string) $server->name])->values()->all(),
            'serverKinds' => array_map(fn(MonitorKind $kind): string => $kind->value, self::SERVER_KINDS),
            'footnote' => $plan
                ? 'Owners and admins can change monitors. Everyone else sees them. A change applies from the next reading.'
                : 'These defaults run on every plan. Changing them, and limits for one server, are part of Pro and Business.',
        ];
    }
}
