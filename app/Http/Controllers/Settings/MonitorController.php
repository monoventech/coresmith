<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Enums\MonitorKind;
use App\Enums\PlanFeature;
use App\Http\Controllers\Controller;
use App\Models\Monitor;
use App\Models\Server;
use App\Models\ServerMonitor;
use App\Models\Team;
use App\Services\Billing\PlanGate;
use App\Services\Monitors\MonitorChoices;
use App\Services\Monitors\MonitorSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class MonitorController extends Controller
{
    public function update(Request $request, MonitorKind $kind, PlanGate $plans): RedirectResponse
    {
        $team = $this->team();

        $validated = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'threshold' => ['sometimes', 'numeric', Rule::in(MonitorChoices::levels($kind))],
            'duration' => ['sometimes', 'integer', Rule::in(MonitorChoices::durations($kind))],
        ]);

        $monitor = Monitor::query()->firstOrNew(['team_id' => $team->getKey(), 'kind' => $kind]);
        $threshold = (float) ($validated['threshold'] ?? ($monitor->exists ? $monitor->threshold : $kind->defaultThreshold()));
        $duration = (int) ($validated['duration'] ?? ($monitor->exists ? $monitor->duration : $kind->defaultDuration()));
        $moved = $threshold !== $kind->defaultThreshold() || $duration !== $kind->defaultDuration();

        if ($moved && ($threshold !== $monitor->threshold || $duration !== $monitor->duration)) {
            $plans->ensureIncludes($team, PlanFeature::Monitors, $request->user());
        }

        $monitor->forceFill(['threshold' => $threshold, 'duration' => $duration, 'enabled' => (bool) ($validated['enabled'] ?? $monitor->enabled ?? true)])->save();

        activity()->performedOn($team)->withProperties([
            'kind' => $kind->value,
            'enabled' => $monitor->enabled,
            'rule' => MonitorChoices::rule($kind, $threshold, $duration),
        ])->log('monitor_changed');

        return back()->with('success', 'Saved.');
    }

    public function updateServer(Request $request, MonitorKind $kind, Server $server, PlanGate $plans): RedirectResponse
    {
        $team = $this->team($server, $kind);

        $plans->ensureIncludes($team, PlanFeature::Monitors, $request->user());

        $validated = $request->validate([
            'threshold' => ['required', 'numeric', Rule::in(MonitorChoices::levels($kind))],
            'duration' => ['required', 'integer', Rule::in(MonitorChoices::durations($kind))],
        ]);

        ServerMonitor::query()->updateOrCreate(
            ['server_id' => $server->getKey(), 'kind' => $kind],
            ['team_id' => $team->getKey(), 'threshold' => (float) $validated['threshold'], 'duration' => (int) $validated['duration']],
        );

        activity()->performedOn($team)->withProperties([
            'kind' => $kind->value,
            'server' => $server->name,
            'rule' => MonitorChoices::serverRule($kind, (float) $validated['threshold'], (int) $validated['duration']),
        ])->log('monitor_server_changed');

        return back()->with('success', 'Saved.');
    }

    public function destroyServer(MonitorKind $kind, Server $server): RedirectResponse
    {
        $team = $this->team($server, $kind);

        ServerMonitor::query()->where('server_id', $server->getKey())->where('kind', $kind)->delete();

        activity()->performedOn($team)->withProperties(['kind' => $kind->value, 'server' => $server->name])->log('monitor_server_removed');

        return back()->with('success', "Removed. {$server->name} follows the team's limit again.");
    }

    private function team(?Server $server = null, ?MonitorKind $kind = null): Team
    {
        $team = $this->currentTeam();

        abort_if($team === null, 404);
        abort_if($server !== null && ($server->team_id !== $team->getKey() || !in_array($kind, MonitorSettings::SERVER_KINDS, true)), 404);

        $this->authorize('update', $team);

        return $team;
    }
}
