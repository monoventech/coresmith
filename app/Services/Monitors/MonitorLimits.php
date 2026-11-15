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
use App\Services\Billing\TeamBilling;

final class MonitorLimits
{
    public function __construct(private readonly TeamBilling $billing) {}

    public function for(Server $server, MonitorKind $kind): MonitorLimit
    {
        return $this->forAll($server, [$kind])[$kind->value];
    }

    /**
     * @param  list<MonitorKind>  $kinds
     * @return array<string, MonitorLimit>
     */
    public function forAll(Server $server, array $kinds): array
    {
        $team = $server->team;
        $values = array_map(fn(MonitorKind $kind): string => $kind->value, $kinds);
        $teamRows = $team === null ? collect() : Monitor::query()->where('team_id', $team->getKey())->whereIn('kind', $values)->get()->keyBy(fn(Monitor $row): string => $row->kind->value);
        $plan = $team !== null && $this->billing->status($team)->allows(PlanFeature::Monitors);
        $serverRows = $plan ? ServerMonitor::query()->where('server_id', $server->getKey())->whereIn('kind', $values)->get()->keyBy(fn(ServerMonitor $row): string => $row->kind->value) : collect();
        $limits = [];

        foreach ($kinds as $kind) {
            $teamRow = $teamRows->get($kind->value);
            $serverRow = $serverRows->get($kind->value);
            $enabled = $teamRow?->enabled ?? true;

            $limits[$kind->value] = match (true) {
                $serverRow !== null => new MonitorLimit($kind, $serverRow->threshold, $serverRow->duration, $enabled, 'server'),
                $plan && $teamRow !== null => new MonitorLimit($kind, $teamRow->threshold, $teamRow->duration, $enabled, 'team'),
                default => new MonitorLimit($kind, $kind->defaultThreshold(), $kind->defaultDuration(), $enabled, 'default'),
            };
        }

        return $limits;
    }
}
