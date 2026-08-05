<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class TeamController extends Controller
{
    public function index(Request $request): Response
    {
        $team = $request->user()->teams()->first();

        abort_if($team === null, 404);

        return $this->show($request, $team);
    }

    public function show(Request $request, Team $team): Response
    {
        $this->authorize('view', $team);

        $user = $request->user();

        return Inertia::render('settings/Team', [
            'team' => ['id' => $team->id, 'name' => $team->name],
            'teams' => $user->teams()->get(['teams.id', 'teams.name'])
                ->map(fn (Team $each): array => ['id' => $each->id, 'name' => $each->name]),
            'members' => $team->members()->orderBy('name')->get()->map(fn (User $member): array => [
                'id' => $member->id,
                'name' => $member->name,
                'email' => $member->email,
                'role' => $member->pivot->role,
                'is_you' => $member->id === $user->id,
            ]),
            'roles' => array_map(
                fn (TeamRole $role): array => ['value' => $role->value, 'label' => ucfirst($role->value)],
                TeamRole::cases(),
            ),
            'can' => [
                'manageMembers' => $user->can('manageMembers', $team),
                'grantOwner' => $user->roleIn($team) === TeamRole::Owner,
            ],
        ]);
    }
}
