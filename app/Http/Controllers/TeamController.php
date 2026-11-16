<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\TeamRole;
use App\Models\Application;
use App\Models\BackupDestination;
use App\Models\ManagedDatabase;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Models\TeamDomain;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Services\Panel\CloudPresenter;
use App\Services\Panel\Formats;
use App\Services\Sso\SsoConnections;
use App\Services\Teams\TeamAccess;
use App\Services\Teams\TeamDomains;
use App\Services\Teams\TeamInvitations;
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
        $manages = $user->can('manageMembers', $team);
        $shares = $user->can('shareAccess', $team);
        $access = app(TeamAccess::class);

        return Inertia::render('settings/Team', [
            'team' => ['id' => $team->id, 'name' => $team->name, 'slug' => $team->slug],
            'address' => [
                'path' => $urls->pathUrl($team, $request),
                'subdomain' => $urls->subdomainUrl($team),
                'domain' => $domain instanceof TeamDomain ? app(TeamDomains::class)->present($domain) : null,
            ],
            'sso' => app(SsoConnections::class)->present($team, $user),
            'cloud' => app(CloudPresenter::class)->accounts($team, $user),
            'offsite' => $this->offsite($team),
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
                'access' => $shares && $member->pivot->role !== TeamRole::Owner->value ? $access->summary($team, $member) : null,
            ]),
            'shareable' => $shares ? $this->shareable($team, $user, $access) : null,
            'invitations' => $this->invitations($request, $team, $user, $manages),
            'invite_links' => app(TeamInvitations::class)->offersLinks(),
            'roles' => array_map(
                fn(TeamRole $role): array => ['value' => $role->value, 'label' => $role->label()],
                TeamRole::cases(),
            ),
            'can' => [
                'update' => $user->can('update', $team),
                'manageMembers' => $manages,
                'grantOwner' => $user->roleIn($team) === TeamRole::Owner,
                'shareAccess' => $shares,
            ],
        ]);
    }

    /**
     * @return list<array{id: string, email: string, role: string, role_label: string, expires: string, link: ?string}>
     */
    private function invitations(Request $request, Team $team, User $user, bool $manages): array
    {
        $issued = $request->session()->get(TeamInvitations::ISSUED);
        $invitations = app(TeamInvitations::class);

        return $team->invitations()->pending()->orderBy('email')->get()->map(fn(TeamInvitation $invitation): array => [
            'id' => (string) $invitation->id,
            'email' => $invitation->email,
            'role' => $invitation->role->value,
            'role_label' => $invitation->role->label(),
            'expires' => $invitation->expires_at->format('M j'),
            'link' => $manages && $invitation->invited_by === $user->id ? $invitations->issuedLink($invitation, $issued) : null,
        ])->values()->all();
    }

    /**
     * @return array{servers: list<array{id: string, name: string, this_machine: bool, applications: list<array{id: string, name: string, tags: list<string>}>, databases: list<array{id: string, name: string}>}>, projects: list<array{id: string, name: string, applications: list<array{id: string, name: string, tags: list<string>}>, databases: list<array{id: string, name: string}>}>}
     */
    private function shareable(Team $team, User $viewer, TeamAccess $access): array
    {
        $applications = $access->applications($viewer, $team)->orderBy('name')->get(['id', 'name', 'server_id', 'project_id', 'tags', 'created_by']);
        $databases = $access->databases($viewer, $team)->orderBy('name')->get(['id', 'name', 'server_id', 'project_id', 'created_by']);
        $developers = $team->members()->wherePivot('role', TeamRole::Developer->value)->get(['users.id', 'users.name'])->keyBy('id');
        $developer = fn(Application|ManagedDatabase $each): ?array => ($by = $developers->get((string) $each->created_by)) === null ? null : ['id' => $by->id, 'name' => $by->name];
        $app = fn(Application $application): array => ['id' => $application->id, 'name' => $application->name, 'tags' => $application->tags, 'developer' => $developer($application)];
        $database = fn(ManagedDatabase $each): array => ['id' => $each->id, 'name' => $each->name, 'developer' => $developer($each)];

        return [
            'servers' => $access->servers($viewer, $team)->orderBy('name')->get(['id', 'name', 'runs_coresmith'])->map(fn(Server $server): array => [
                'id' => $server->id,
                'name' => $server->name,
                'this_machine' => (bool) $server->runs_coresmith,
                'applications' => $applications->where('server_id', $server->id)->map($app)->values()->all(),
                'databases' => $databases->where('server_id', $server->id)->map($database)->values()->all(),
            ])->values()->all(),
            'projects' => $access->projects($viewer, $team)->orderBy('name')->get()->map(fn(Project $project): array => [
                'id' => $project->id,
                'name' => $project->name,
                'applications' => $applications->where('project_id', $project->id)->map($app)->values()->all(),
                'databases' => $databases->where('project_id', $project->id)->map($database)->values()->all(),
            ])->values()->all(),
        ];
    }

    /**
     * @return array{endpoint: ?string, region: string, bucket: string, prefix: ?string, path_style: bool, last_run: ?string, failing: int}|null
     */
    private function offsite(Team $team): ?array
    {
        $destination = $team->backupDestination()->first();

        if (!$destination instanceof BackupDestination) {
            return null;
        }

        return [
            'endpoint' => $destination->endpoint,
            'region' => $destination->region,
            'bucket' => $destination->bucket,
            'prefix' => $destination->prefix,
            'path_style' => $destination->path_style,
            'last_run' => Formats::when($destination->last_run_at),
            'failing' => ManagedDatabase::query()->where('team_id', $team->getKey())->whereNotNull('last_offsite_error')->count(),
        ];
    }
}
