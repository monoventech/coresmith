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
        $team = $server->team;
        $teamRow = $team === null ? null : Monitor::query()->where('team_id', $team->getKey())->where('kind', $kind)->first();
        $enabled = $teamRow?->enabled ?? true;

        if ($team !== null && $this->billing->status($team)->allows(PlanFeature::Monitors)) {
            $serverRow = ServerMonitor::query()->where('server_id', $server->getKey())->where('kind', $kind)->first();

            if ($serverRow !== null) {
                return new MonitorLimit($kind, $serverRow->threshold, $serverRow->duration, $enabled, 'server');
            }

            if ($teamRow !== null) {
                return new MonitorLimit($kind, $teamRow->threshold, $teamRow->duration, $enabled, 'team');
            }
        }

        return new MonitorLimit($kind, $kind->defaultThreshold(), $kind->defaultDuration(), $enabled, 'default');
    }
}
