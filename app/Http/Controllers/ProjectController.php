<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Project\ProjectNameRequest;
use App\Models\Application;
use App\Models\Project;
use App\Models\Server;
use App\Services\Panel\FleetSnapshot;
use App\Services\Panel\ProjectPresenter;
use App\Services\Projects\Projects;
use App\Services\Teams\TeamAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class ProjectController extends Controller
{
    public function index(Request $request, ProjectPresenter $presenter, TeamAccess $access): Response|RedirectResponse
    {
        $team = $this->currentTeam();

        if ($team === null) {
            return to_route('dashboard');
        }

        $user = $request->user();
        $fleet = new FleetSnapshot($team, $user);
        $servers = $fleet->servers();

        return Inertia::render('projects/Index', [
            'counts' => [
                'servers' => $servers->count(),
                'applications' => $servers->sum(fn(Server $server): int => $server->applications->count()) + $fleet->sharedApplications()->count(),
            ],
            'projects' => $access->projects($user, $team)->orderBy('name')->get()->map(fn(Project $project): array => $presenter->card($project, $user))->values(),
            'unfiled' => $access->applications($user, $team)->whereNull('environment_id')->with('server')->orderBy('name')->get()->map(fn(Application $application): array => [
                'id' => $application->getKey(),
                'name' => $application->name,
                'server' => $application->server?->name,
                'href' => route('servers.applications.show', [$application->server_id, $application]),
            ])->values(),
            'narrowed' => !$access->seesEverything($user, $team),
            'can' => ['create' => $user->can('create', [Project::class, $team])],
        ]);
    }

    public function show(Request $request, Project $project, ProjectPresenter $presenter): Response
    {
        $this->authorize('view', $project);

        return Inertia::render('projects/Show', $presenter->page($project, $request->user()));
    }

    public function store(ProjectNameRequest $request, Projects $projects): RedirectResponse
    {
        $team = $this->currentTeam();

        abort_if($team === null, 404);

        $project = $projects->create($team, $request->string('name')->value(), $request->user());

        return to_route('projects.show', $project)->with('success', "Created {$project->name}.");
    }

    public function update(ProjectNameRequest $request, Project $project, Projects $projects): RedirectResponse
    {
        $projects->rename($project, $request->string('name')->value(), $request->user());

        return back()->with('success', 'Saved.');
    }

    public function destroy(Request $request, Project $project, Projects $projects): RedirectResponse
    {
        $this->authorize('manage', $project);

        $projects->delete($project, $request->user());

        return to_route('projects.index')->with('success', "Deleted {$project->name}. What was in it keeps running, under No project.");
    }
}
