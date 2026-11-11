<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\Edition;
use App\Enums\TeamRole;
use App\Jobs\SyncAuthorizedKeys;
use App\Models\Team;
use App\Models\User;
use App\Services\Billing\PlanGate;
use App\Services\Teams\TeamAccess;
use App\Services\Teams\TeamInvitations;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class TeamMemberController extends Controller
{
    private const ALREADY_ON_TEAM = 'That person is already on this team.';

    private const OWNER_GRANT = 'Only an owner can grant the owner role. Ask an owner of this team to do it.';

    private const OWNER_CHANGE = 'Only an owner can change or remove an owner. Ask an owner of this team to do it.';

    private const LAST_OWNER = 'A team must keep at least one owner. Make someone else an owner first.';

    public function store(Request $request, Team $team, PlanGate $plans, TeamInvitations $invitations, TeamAccess $access): RedirectResponse
    {
        $this->authorize('manageMembers', $team);

        $plans->ensureCanAddMember($team, $request->user());

        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', Rule::enum(TeamRole::class)],
            'access' => ['sometimes', Rule::in(['everything', 'shared'])],
        ]);

        $email = Str::lower($validated['email']);
        $role = TeamRole::from($validated['role']);
        $user = User::findByEmail($email);

        $errors = array_filter([
            'email' => $user instanceof User && $team->members()->whereKey($user->getKey())->exists() ? self::ALREADY_ON_TEAM : null,
            'role' => $this->refusesOwnerGrant($request, $team, $role) ? self::OWNER_GRANT : null,
        ]);

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $everything = $request->user()->roleIn($team) === TeamRole::Owner
            ? ($validated['access'] ?? 'everything') === 'everything'
            : $access->seesEverything($request->user(), $team);

        if (!$user instanceof User || !$this->joinsAtOnce($user)) {
            return $this->invite($request, $team, $email, $role, $everything, $invitations);
        }

        $team->members()->attach($user, ['role' => $role->value, 'sees_everything' => $everything]);
        $team->invitations()->where('email', $email)->delete();

        activity()->performedOn($team)->causedBy($request->user())->withProperties(['email' => $user->email, 'role' => $role->value])->log('member_added');

        $this->syncServers($team);

        return back()->with('success', 'Member added.');
    }

    public function update(Request $request, Team $team, User $user): RedirectResponse
    {
        $this->authorize('manageMembers', $team);

        $validated = $request->validate(['role' => ['required', Rule::enum(TeamRole::class)]]);

        $role = TeamRole::from($validated['role']);

        if ($this->refusesOwnerChange($request, $team, $user)) {
            return back()->with('error', self::OWNER_CHANGE);
        }

        if ($this->refusesOwnerGrant($request, $team, $role)) {
            return back()->with('error', self::OWNER_GRANT);
        }

        if ($this->leavesNoOwner($team, $user, $role)) {
            return back()->with('error', self::LAST_OWNER);
        }

        $from = $user->roleIn($team);

        $team->members()->updateExistingPivot($user->getKey(), ['role' => $role->value]);

        activity()->performedOn($team)->causedBy($request->user())->withProperties(['email' => $user->email, 'from' => $from?->value, 'to' => $role->value])->log('member_role_changed');

        $this->syncServers($team);

        return back()->with('success', 'Role updated.');
    }

    public function destroy(Request $request, Team $team, User $user, TeamAccess $access): RedirectResponse
    {
        $this->authorize('manageMembers', $team);

        if ($this->refusesOwnerChange($request, $team, $user)) {
            return back()->with('error', self::OWNER_CHANGE);
        }

        if ($this->leavesNoOwner($team, $user, null)) {
            return back()->with('error', self::LAST_OWNER);
        }

        $team->members()->detach($user->getKey());
        $access->remove($team, $user);

        activity()->performedOn($team)->causedBy($request->user())->withProperties(['email' => $user->email])->log('member_removed');

        $this->syncServers($team);

        return back()->with('success', 'Member removed.');
    }

    private function invite(Request $request, Team $team, string $email, TeamRole $role, bool $everything, TeamInvitations $invitations): RedirectResponse
    {
        $key = 'team-invitations:' . $team->getKey();
        $limit = (int) config('coresmith.teams.invitations_per_hour', 20);

        if (RateLimiter::tooManyAttempts($key, $limit)) {
            $minutes = max(1, (int) ceil(RateLimiter::availableIn($key) / 60));

            throw ValidationException::withMessages(['email' => "This team has sent {$limit} invitations in the last hour. Try again in {$minutes} " . Str::plural('minute', $minutes) . '.']);
        }

        RateLimiter::hit($key, 3600);

        [$invitation, $token] = $invitations->invite($team, $email, $role, $request->user(), $everything);

        if (!$invitation->link_offered) {
            return back()->with('success', "Invitation sent to {$invitation->email}.");
        }

        return back()->with([
            'success' => "Invitation created. Coresmith cannot send email yet, so copy the link below and send it to {$invitation->email} yourself.",
            TeamInvitations::ISSUED => ['id' => $invitation->getKey(), 'token' => $token],
        ]);
    }

    private function joinsAtOnce(User $user): bool
    {
        return Edition::current()->isSelfHosted() && $user->hasVerifiedEmail();
    }

    private function syncServers(Team $team): void
    {
        SyncAuthorizedKeys::dispatchForServers(SyncAuthorizedKeys::activeServersOf([$team->getKey()]));
    }

    private function refusesOwnerChange(Request $request, Team $team, User $user): bool
    {
        return $user->roleIn($team) === TeamRole::Owner && $request->user()->roleIn($team) !== TeamRole::Owner;
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
