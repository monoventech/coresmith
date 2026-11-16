<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\Ability;
use App\Enums\ServerStatus;
use App\Models\Application;
use App\Models\Server;
use App\Services\Panel\FleetSnapshot;
use App\Services\Teams\TeamAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class AddApplicationController extends Controller
{
    public function __invoke(Request $request, TeamAccess $access): Response|RedirectResponse
    {
        $this->authorize('create', Application::class);

        $team = $this->currentTeam();

        if ($team === null) {
            return to_route('dashboard');
        }

        $user = $request->user();
        $project = is_string($request->query('project')) ? ['project' => $request->query('project')] : [];
        $servers = $access->servers($user, $team)->where('status', ServerStatus::Active)->withCount('applications')->orderBy('name')->get();

        if ($servers->count() === 1) {
            return to_route('servers.applications.create', [$servers->first(), ...$project]);
        }

        $fleet = new FleetSnapshot($team, $user);

        return Inertia::render('applications/ChooseServer', [
            'servers' => $servers->map(fn(Server $server): array => [
                'id' => $server->getKey(),
                'name' => $server->name,
                'href' => route('servers.applications.create', [$server, ...$project]),
                'state' => $fleet->state($server),
                'meta' => $server->applications_count . ' ' . ($server->applications_count === 1 ? 'app' : 'apps'),
            ])->values()->all(),
            'can' => ['createServer' => $user->hasTeamAbility($team, Ability::CreateServer)],
        ]);
    }
}
