<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\Ability;
use App\Enums\DeploymentStatus;
use App\Enums\PowerState;
use App\Enums\ServerStatus;
use App\Models\Deployment;
use App\Models\ManagedDatabase;
use App\Models\Team;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class DashboardController extends Controller
{
    public const ATTENTION_LIMIT = 5;

    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $team = $user->teams()->first();

        if ($team === null) {
            return Inertia::render('Dashboard', [
                'hasTeam' => false,
                'counts' => ['servers' => 0, 'applications' => 0, 'databases' => 0],
                'attention' => [],
                'attentionTotal' => 0,
                'deployments' => [],
                'can' => ['createServer' => false],
            ]);
        }

        $attention = $this->attention($team);

        return Inertia::render('Dashboard', [
            'hasTeam' => true,
            'counts' => [
                'servers' => $team->servers()->count(),
                'applications' => $team->applications()->count(),
                'databases' => ManagedDatabase::where('team_id', $team->getKey())->count(),
            ],
            'attention' => array_slice($attention, 0, self::ATTENTION_LIMIT),
            'attentionTotal' => count($attention),
            'deployments' => $this->recentDeployments($team),
            'can' => ['createServer' => $user->hasTeamAbility($team, Ability::CreateServer)],
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function attention(Team $team): array
    {
        $items = [];

        foreach ($team->servers()->whereNotNull('pending_host_key_fingerprint')->get() as $server) {
            $items[] = [
                'severity' => 'critical',
                'title' => "{$server->name} presented a different host key",
                'detail' => 'Every operation on this server is blocked until you review it.',
                'href' => route('servers.show', $server),
            ];
        }

        foreach ($team->applications()->where('power_state', PowerState::Stopped->value)->get() as $application) {
            $items[] = [
                'severity' => 'warning',
                'title' => "{$application->name} is stopped",
                'detail' => 'Visitors see a maintenance page instead of the site.',
                'href' => route('servers.applications.show', [$application->server_id, $application]),
            ];
        }

        foreach ($team->servers()->where('status', ServerStatus::Failed->value)->get() as $server) {
            $items[] = [
                'severity' => 'critical',
                'title' => "{$server->name} failed to provision",
                'detail' => 'Read the provisioning log to see where it stopped.',
                'href' => route('servers.show', $server),
            ];
        }

        $failed = Deployment::query()
            ->whereIn('application_id', $team->applications()->select('id'))
            ->where('status', DeploymentStatus::Failed->value)
            ->with('application')
            ->latest()
            ->limit(3)
            ->get();

        foreach ($failed as $deployment) {
            $items[] = [
                'severity' => 'warning',
                'title' => "The last deployment of {$deployment->application->name} failed",
                'detail' => 'Open the deployment to read its output.',
                'href' => route('servers.applications.deployments.show', [
                    $deployment->application->server_id,
                    $deployment->application_id,
                    $deployment,
                ]),
            ];
        }

        return $items;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recentDeployments(Team $team): array
    {
        return Deployment::query()
            ->whereIn('application_id', $team->applications()->select('id'))
            ->with('application')
            ->latest()
            ->limit(6)
            ->get()
            ->map(fn (Deployment $deployment): array => [
                'id' => $deployment->id,
                'application' => $deployment->application->name,
                'status' => $deployment->status->value,
                'commit_message' => $deployment->commit_message,
                'finished_at' => $deployment->finished_at?->toIso8601String(),
                'href' => route('servers.applications.deployments.show', [
                    $deployment->application->server_id,
                    $deployment->application_id,
                    $deployment,
                ]),
            ])->values()->all();
    }
}
