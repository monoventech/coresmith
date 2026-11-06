<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\Ability;
use App\Enums\Edition;
use App\Models\Deployment;
use App\Models\Server;
use App\Services\Deployment\DeploymentPhases;
use App\Services\Panel\ActivityFeed;
use App\Services\Panel\FleetSnapshot;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class DashboardController extends Controller
{
    public function __invoke(Request $request, ActivityFeed $feed, DeploymentPhases $phases): Response|RedirectResponse
    {
        $user = $request->user();
        $team = $this->currentTeam();

        if ($team === null && Edition::current()->isCloud()) {
            return to_route('start.show');
        }

        if ($team === null) {
            return Inertia::render('Dashboard', [
                'hasTeam' => false,
                'counts' => ['servers' => 0, 'applications' => 0],
                'live' => null,
                'tiles' => [],
                'servers' => [],
                'activity' => [],
                'can' => ['createServer' => false],
            ]);
        }

        $fleet = new FleetSnapshot($team);
        $servers = $fleet->servers();
        $applications = $servers->sum(fn(Server $server): int => $server->applications->count());
        $attention = $fleet->attention();
        $running = $fleet->running();
        $uptime = $fleet->uptime();
        $busiest = $servers->sortByDesc(fn(Server $server): int => $server->applications->count())->first();

        return Inertia::render('Dashboard', [
            'hasTeam' => true,
            'counts' => ['servers' => $servers->count(), 'applications' => $applications],
            'live' => $this->live($running->first(), $phases),
            'tiles' => [
                [
                    'id' => 'applications',
                    'label' => 'Applications',
                    'value' => (string) $applications,
                    'sub' => 'across ' . $servers->count() . ' ' . ($servers->count() === 1 ? 'server' : 'servers'),
                    'tone' => 'default',
                    'href' => $busiest === null ? route('dashboard') : route('servers.show', $busiest),
                ],
                [
                    'id' => 'deploys',
                    'label' => 'Deploys this week',
                    'value' => (string) $fleet->deploysThisWeek(),
                    'sub' => $running->isEmpty() ? 'none running' : $running->count() . ' running now',
                    'tone' => 'default',
                    'href' => route('deployments.index'),
                ],
                [
                    'id' => 'attention',
                    'label' => 'Needs attention',
                    'value' => (string) count($attention),
                    'sub' => $attention === [] ? 'all clear' : $attention[0]['subject'] . ' · ' . $attention[0]['summary'],
                    'tone' => $attention === [] ? 'default' : 'warning',
                    'href' => $attention === [] ? route('deployments.index') : $attention[0]['href'],
                ],
                [
                    'id' => 'uptime',
                    'label' => 'Uptime, ' . FleetSnapshot::UPTIME_DAYS . ' days',
                    'value' => $uptime === null ? '—' : preg_replace('/\.?0+$/', '', number_format($uptime, 2)) . '%',
                    'sub' => $uptime === null ? 'no readings yet' : 'fleet-wide',
                    'tone' => 'default',
                    'href' => route('deployments.index'),
                ],
            ],
            'servers' => $servers->map(fn(Server $server): array => $fleet->card($server))->values(),
            'activity' => $feed->forTeam($team),
            'can' => ['createServer' => $user->hasTeamAbility($team, Ability::CreateServer)],
        ]);
    }

    /**
     * @return array<string, string>|null
     */
    private function live(?Deployment $deployment, DeploymentPhases $phases): ?array
    {
        if ($deployment === null || $deployment->application === null) {
            return null;
        }

        $application = $deployment->application;

        return [
            'application' => $application->name,
            'server' => (string) $application->server?->name,
            'phase' => $phases->progress($deployment) ?? 'starting',
            'href' => route('servers.applications.deployments.show', [$application->server_id, $application, $deployment]),
        ];
    }
}
