<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\TeamDomain;
use App\Models\User;
use App\Services\Panel\CloudPresenter;
use App\Services\Sso\SsoConnections;
use App\Services\Teams\TeamDomains;
use App\Services\Teams\TeamUrls;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class TeamController extends Controller
{
    public function index(Request $request): Response
    {
        $team = $this->currentTeam();

        abort_if($team === null, 404);

        return $this->show($request, $team);
    }

    public function show(Request $request, Team $team): Response
    {
        $this->authorize('view', $team);

        $user = $request->user();
        $urls = app(TeamUrls::class);
        $domain = $team->domain()->first();

        return Inertia::render('settings/Team', [
            'team' => ['id' => $team->id, 'name' => $team->name, 'slug' => $team->slug],
            'address' => [
                'path' => $urls->pathUrl($team, $request),
                'subdomain' => $urls->subdomainUrl($team),
                'domain' => $domain instanceof TeamDomain ? app(TeamDomains::class)->present($domain) : null,
            ],
            'sso' => app(SsoConnections::class)->present($team, $user),
            'cloud' => app(CloudPresenter::class)->accounts($team, $user),
            'teams' => $user->teams()->get(['teams.id', 'teams.name'])
                ->map(fn(Team $each): array => ['id' => $each->id, 'name' => $each->name]),
            'members' => $team->members()->orderBy('name')->get()->map(fn(User $member): array => [
                'id' => $member->id,
                'name' => $member->name,
                'email' => $member->email,
                'role' => $member->pivot->role,
                'role_label' => TeamRole::tryFrom((string) $member->pivot->role)?->label(),
                'two_factor' => $member->two_factor_confirmed_at !== null,
                'is_you' => $member->id === $user->id,
            ]),
            'roles' => array_map(
                fn(TeamRole $role): array => ['value' => $role->value, 'label' => $role->label()],
                TeamRole::cases(),
            ),
            'can' => [
                'update' => $user->can('update', $team),
                'manageMembers' => $user->can('manageMembers', $team),
                'grantOwner' => $user->roleIn($team) === TeamRole::Owner,
            ],
        ]);
    }
}
