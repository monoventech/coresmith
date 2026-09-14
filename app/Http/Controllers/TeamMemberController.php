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

final class TeamMemberController extends Controller
{
    public function store(Request $request, Team $team): RedirectResponse
    {
        $this->authorize('manageMembers', $team);

        $validated = $request->validate([
            'email' => ['required', 'email', Rule::exists('users', 'email')],
            'role' => ['required', Rule::enum(TeamRole::class)],
        ]);

        $this->guardOwnerRole($request, $team, $validated['role']);

        $user = User::where('email', $validated['email'])->firstOrFail();

        abort_if($team->members()->whereKey($user->getKey())->exists(), 422, 'That person is already on this team.');

        $team->members()->attach($user, ['role' => $validated['role']]);

        $this->syncServers($team);

        return back()->with('success', 'Member added.');
    }

    public function update(Request $request, Team $team, User $user): RedirectResponse
    {
        $this->authorize('manageMembers', $team);

        $validated = $request->validate(['role' => ['required', Rule::enum(TeamRole::class)]]);

        $this->guardOwnerRole($request, $team, $validated['role']);
        $this->guardLastOwner($team, $user, TeamRole::from($validated['role']));

        $team->members()->updateExistingPivot($user->getKey(), ['role' => $validated['role']]);

        $this->syncServers($team);

        return back()->with('success', 'Role updated.');
    }

    public function destroy(Team $team, User $user): RedirectResponse
    {
        $this->authorize('manageMembers', $team);

        $this->guardLastOwner($team, $user, null);

        $team->members()->detach($user->getKey());

        $this->syncServers($team);

        return back()->with('success', 'Member removed.');
    }

    private function syncServers(Team $team): void
    {
        SyncAuthorizedKeys::dispatchForServers(SyncAuthorizedKeys::activeServersOf([$team->getKey()]));
    }

    private function guardOwnerRole(Request $request, Team $team, string $role): void
    {
        if ($role !== TeamRole::Owner->value) {
            return;
        }

        abort_unless($request->user()->roleIn($team) === TeamRole::Owner, 403, 'Only an owner can grant the owner role.');
    }

    private function guardLastOwner(Team $team, User $user, ?TeamRole $newRole): void
    {
        if ($newRole === TeamRole::Owner) {
            return;
        }

        $owners = $team->members()->wherePivot('role', TeamRole::Owner->value)->pluck('users.id');

        abort_if(
            $owners->count() === 1 && $owners->first() === $user->getKey(),
            422,
            'A team must keep at least one owner.',
        );
    }
}
