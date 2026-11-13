<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\TeamRole;
use App\Models\Application;
use App\Models\Environment;
use App\Models\ManagedDatabase;
use App\Models\Project;
use App\Models\User;
use App\Services\Projects\Projects;
use App\Services\Teams\TeamAccess;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class EnvironmentContentsController extends Controller
{
    public function store(Request $request, Project $project, Environment $environment, Projects $projects, TeamAccess $access): RedirectResponse
    {
        $this->authorize('fill', $environment);

        $validated = $request->validate([
            'applications' => ['sometimes', 'array'],
            'applications.*' => ['string'],
            'databases' => ['sometimes', 'array'],
            'databases.*' => ['string'],
        ]);

        $user = $request->user();
        $developer = $user->roleIn($project->team) === TeamRole::Developer;
        $applications = $this->seen(Application::query()->whereIn('id', $validated['applications'] ?? [])->get(), $validated['applications'] ?? [], $project, fn(Application $each): bool => $access->canSeeApplication($user, $each), 'applications', 'apps');
        $databases = $this->seen(ManagedDatabase::query()->whereIn('id', $validated['databases'] ?? [])->get(), $validated['databases'] ?? [], $project, fn(ManagedDatabase $each): bool => $access->canSeeDatabase($user, $each), 'databases', 'databases');

        if ($developer) {
            $this->added($applications, $user, 'applications', 'apps');
            $this->added($databases, $user, 'databases', 'databases');
        }

        $projects->file($environment, $applications, $databases, $user);

        return back()->with('success', "Added to {$environment->name}.");
    }

    public function destroyApplication(Request $request, Project $project, Environment $environment, Application $application, Projects $projects, TeamAccess $access): RedirectResponse
    {
        $this->authorize('fill', $environment);
        abort_unless($access->canSeeApplication($request->user(), $application), 404);
        abort_if($request->user()->roleIn($project->team) === TeamRole::Developer && !$application->addedBy($request->user()), 403);

        $projects->unfile($application, $request->user());

        return back()->with('success', "Took {$application->name} out of {$project->name}.");
    }

    public function destroyDatabase(Request $request, Project $project, Environment $environment, ManagedDatabase $database, Projects $projects, TeamAccess $access): RedirectResponse
    {
        $this->authorize('fill', $environment);
        abort_unless($access->canSeeDatabase($request->user(), $database), 404);
        abort_if($request->user()->roleIn($project->team) === TeamRole::Developer && !$database->addedBy($request->user()), 403);

        $projects->unfile($database, $request->user());

        return back()->with('success', "Took {$database->name} out of {$project->name}.");
    }

    /**
     * @template T of Application|ManagedDatabase
     *
     * @param  Collection<int, T>  $found
     * @param  list<string>  $ids
     * @param  callable(T): bool  $visible
     * @return Collection<int, T>
     */
    private function seen(Collection $found, array $ids, Project $project, callable $visible, string $field, string $noun): Collection
    {
        $usable = $found->filter(fn(Application|ManagedDatabase $each): bool => $each->team_id === $project->team_id && $visible($each));

        if ($usable->count() !== count(array_unique($ids))) {
            throw ValidationException::withMessages([$field => "One of those {$noun} isn't one you can see. Reload the page and choose again."]);
        }

        return $usable;
    }

    /**
     * @param  Collection<int, Application>|Collection<int, ManagedDatabase>  $items
     */
    private function added(Collection $items, User $user, string $field, string $noun): void
    {
        if ($items->contains(fn(Application|ManagedDatabase $each): bool => !$each->addedBy($user))) {
            throw ValidationException::withMessages([$field => "You can only move {$noun} you added. Reload the page and choose again."]);
        }
    }
}
