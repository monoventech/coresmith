<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Project\EnvironmentNameRequest;
use App\Models\Environment;
use App\Models\Project;
use App\Services\Projects\Projects;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class ProjectEnvironmentController extends Controller
{
    public function store(EnvironmentNameRequest $request, Project $project, Projects $projects): RedirectResponse
    {
        $environment = $projects->addEnvironment($project, $request->string('name')->value(), $request->user());

        return back()->with('success', "Added {$environment->name}.");
    }

    public function update(EnvironmentNameRequest $request, Project $project, Environment $environment, Projects $projects): RedirectResponse
    {
        $projects->renameEnvironment($environment, $request->string('name')->value(), $request->user());

        return back()->with('success', 'Saved.');
    }

    public function destroy(Request $request, Project $project, Environment $environment, Projects $projects): RedirectResponse
    {
        $this->authorize('manage', $project);

        if (!$projects->deleteEnvironment($environment, $request->user())) {
            return back()->with('error', 'A project needs at least one environment. Delete the project instead.');
        }

        return back()->with('success', "Deleted {$environment->name}. What was in it keeps running, under No project.");
    }
}
