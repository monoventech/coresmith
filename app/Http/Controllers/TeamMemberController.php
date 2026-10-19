<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\TeamRole;
use App\Jobs\SyncAuthorizedKeys;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class TeamMemberController extends Controller
{
    private const ALREADY_ON_TEAM = 'That person is already on this team.';

    private const OWNER_GRANT = 'Only an owner can grant the owner role.';

    private const LAST_OWNER = 'A team must keep at least one owner. Make someone else an owner first.';

    public function store(Request $request, Team $team): RedirectResponse
    {
        $this->authorize('manageMembers', $team);

        $validated = $request->validate([
            'email' => ['required', 'email', Rule::exists('users', 'email')],
            'role' => ['required', Rule::enum(TeamRole::class)],
        ]);

        $role = TeamRole::from($validated['role']);
        $user = User::where('email', $validated['email'])->firstOrFail();

        $errors = array_filter([
            'email' => $team->members()->whereKey($user->getKey())->exists() ? self::ALREADY_ON_TEAM : null,
            'role' => $this->refusesOwnerGrant($request, $team, $role) ? self::OWNER_GRANT : null,
        ]);

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $team->members()->attach($user, ['role' => $role->value]);

        $this->syncServers($team);

        return back()->with('success', 'Member added.');
    }

    public function update(Request $request, Team $team, User $user): RedirectResponse
    {
        $this->authorize('manageMembers', $team);

        $validated = $request->validate(['role' => ['required', Rule::enum(TeamRole::class)]]);

        $role = TeamRole::from($validated['role']);

        if ($this->refusesOwnerGrant($request, $team, $role)) {
            return back()->with('error', self::OWNER_GRANT);
        }

        if ($this->leavesNoOwner($team, $user, $role)) {
            return back()->with('error', self::LAST_OWNER);
        }

        $team->members()->updateExistingPivot($user->getKey(), ['role' => $role->value]);

        $this->syncServers($team);

        return back()->with('success', 'Role updated.');
    }

    public function destroy(Team $team, User $user): RedirectResponse
    {
        $this->authorize('manageMembers', $team);

        if ($this->leavesNoOwner($team, $user, null)) {
            return back()->with('error', self::LAST_OWNER);
        }

        $team->members()->detach($user->getKey());

        $this->syncServers($team);

        return back()->with('success', 'Member removed.');
    }

    private function syncServers(Team $team): void
    {
        SyncAuthorizedKeys::dispatchForServers(SyncAuthorizedKeys::activeServersOf([$team->getKey()]));
    }

    private function refusesOwnerGrant(Request $request, Team $team, TeamRole $role): bool
    {
        return $role === TeamRole::Owner && $request->user()->roleIn($team) !== TeamRole::Owner;
    }

    private function leavesNoOwner(Team $team, User $user, ?TeamRole $newRole): bool
    {
        if ($newRole === TeamRole::Owner) {
            return false;
        }

        $owners = $team->members()->wherePivot('role', TeamRole::Owner->value)->pluck('users.id');

        return $owners->count() === 1 && $owners->first() === $user->getKey();
    }
}
