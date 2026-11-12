<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\TeamRole;
use App\Jobs\SyncAuthorizedKeys;
use App\Models\Environment;
use App\Models\Team;
use App\Models\User;
use App\Services\Teams\TeamAccess;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class TeamMemberAccessController extends Controller
{
    public function update(Request $request, Team $team, User $user, TeamAccess $access): RedirectResponse
    {
        $this->authorize('shareAccess', $team);

        $role = $user->roleIn($team);

        abort_if($role === null, 404);
        abort_if($role === TeamRole::Owner, 403);

        $validated = $request->validate([
            'everything' => ['required', 'boolean'],
            'servers' => ['sometimes', 'array', $this->within($team->servers(), "One of those servers isn't in {$team->name} any more. Reload the page and choose again.")],
            'applications' => ['sometimes', 'array', $this->within($team->applications(), "One of those apps isn't in {$team->name} any more. Reload the page and choose again.")],
            'projects' => ['sometimes', 'array', $this->within($team->projects(), "One of those projects isn't in {$team->name} any more. Reload the page and choose again.")],
            'environments' => ['sometimes', 'array', $this->within(Environment::query()->whereIn('project_id', $team->projects()->select('id')), "One of those environments isn't in {$team->name} any more. Reload the page and choose again.")],
        ]);

        $everything = (bool) $validated['everything'];
        $servers = array_values(array_unique($validated['servers'] ?? []));
        $applications = array_values(array_unique($validated['applications'] ?? []));
        $projects = array_values(array_unique($validated['projects'] ?? []));
        $environments = array_values(array_unique($validated['environments'] ?? []));

        $access->sync($team, $user, $everything, $servers, $applications, $projects, $environments);

        activity()->performedOn($team)->causedBy($request->user())->withProperties([
            'email' => $user->email,
            'everything' => $everything,
            'servers' => $servers,
            'applications' => $applications,
            'projects' => $projects,
            'environments' => $environments,
        ])->log('member_access_changed');

        if ($role === TeamRole::Admin) {
            SyncAuthorizedKeys::dispatchForServers(SyncAuthorizedKeys::activeServersOf([$team->getKey()]));
        }

        return back()->with('success', 'Saved.');
    }

    private function within(HasMany|Builder $owned, string $message): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($owned, $message): void {
            $ids = is_array($value) ? $value : [];
            $strings = array_filter($ids, is_string(...));
            $unique = array_unique($strings);

            if (count($strings) !== count($ids) || (clone $owned)->whereIn('id', $unique)->count() !== count($unique)) {
                $fail($message);
            }
        };
    }
}
