<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Project\ProjectNameRequest;
use App\Models\Project;
use App\Services\Projects\Projects;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class ProjectController extends Controller
{
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
