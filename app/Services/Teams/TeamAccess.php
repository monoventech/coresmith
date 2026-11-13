<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Teams;

use App\Enums\TeamRole;
use App\Models\AccessGrant;
use App\Models\Application;
use App\Models\Environment;
use App\Models\ManagedDatabase;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

final class TeamAccess
{
    /** @var array<string, array{member: bool, everything: bool}> */
    private array $memberships = [];

    /** @var array<string, array{servers: list<string>, applications: list<string>, projects: list<string>, environments: list<string>}> */
    private array $grants = [];

    /** @var array<string, list<string>> */
    private array $environments = [];

    /** @var array<string, list<string>> */
    private array $reached = [];

    public function seesEverything(User $user, Team $team): bool
    {
        return $this->membership($user, $team)['everything'];
    }

    public function servers(User $user, Team $team): HasMany
    {
        $servers = $team->servers();

        if ($this->seesEverything($user, $team)) {
            return $servers;
        }

        $servers->whereIn('id', $this->granted($user, $team)['servers']);

        return $servers;
    }

    public function applications(User $user, Team $team): HasMany
    {
        $applications = $team->applications();

        if ($this->seesEverything($user, $team)) {
            return $applications;
        }

        $granted = $this->granted($user, $team);
        $environments = $this->environmentIds($user, $team);
        $applications->where(fn($query) => $query->whereIn('server_id', $granted['servers'])->orWhereIn('id', $granted['applications'])->orWhereIn('environment_id', $environments));

        return $applications;
    }

    public function databases(User $user, Team $team): HasMany
    {
        $databases = $team->databases();

        if ($this->seesEverything($user, $team)) {
            return $databases;
        }

        $servers = $this->granted($user, $team)['servers'];
        $environments = $this->environmentIds($user, $team);
        $databases->where(fn($query) => $query->whereIn('server_id', $servers)->orWhereIn('environment_id', $environments));

        return $databases;
    }

    public function projects(User $user, Team $team): HasMany
    {
        $projects = $team->projects();

        if ($this->seesEverything($user, $team)) {
            return $projects;
        }

        $projects->whereIn('id', $this->projectIds($user, $team));

        return $projects;
    }

    public function canSeeServer(User $user, Server $server): bool
    {
        $team = $server->team;

        if ($team === null || !$this->membership($user, $team)['member']) {
            return false;
        }

        return $this->seesEverything($user, $team) || in_array($server->getKey(), $this->granted($user, $team)['servers'], true);
    }

    public function canSeeApplication(User $user, Application $application): bool
    {
        $team = $application->team;

        if ($team === null || !$this->membership($user, $team)['member']) {
            return false;
        }

        if ($this->seesEverything($user, $team)) {
            return true;
        }

        $granted = $this->granted($user, $team);

        return in_array($application->getKey(), $granted['applications'], true)
            || in_array($application->server_id, $granted['servers'], true)
            || in_array($application->environment_id, $this->environmentIds($user, $team), true);
    }

    public function canSeeDatabase(User $user, ManagedDatabase $database): bool
    {
        $team = $database->team;

        if ($team === null || !$this->membership($user, $team)['member']) {
            return false;
        }

        return $this->seesEverything($user, $team)
            || in_array($database->server_id, $this->granted($user, $team)['servers'], true)
            || in_array($database->environment_id, $this->environmentIds($user, $team), true);
    }

    public function canSeeProject(User $user, Project $project): bool
    {
        $team = $project->team;

        if ($team === null || !$this->membership($user, $team)['member']) {
            return false;
        }

        return $this->seesEverything($user, $team) || in_array($project->getKey(), $this->projectIds($user, $team), true);
    }

    public function seesWholeProject(User $user, Project $project): bool
    {
        $team = $project->team;

        if ($team === null || !$this->membership($user, $team)['member']) {
            return false;
        }

        return $this->seesEverything($user, $team) || in_array($project->getKey(), $this->granted($user, $team)['projects'], true);
    }

    public function canSeeEnvironment(User $user, Environment $environment): bool
    {
        $team = $environment->project?->team;

        if ($team === null || !$this->membership($user, $team)['member']) {
            return false;
        }

        return $this->seesEverything($user, $team) || in_array($environment->getKey(), $this->environmentIds($user, $team), true);
    }

    /**
     * @return Collection<int, Environment>
     */
    public function environmentsOf(User $user, Project $project): Collection
    {
        if ($this->seesWholeProject($user, $project)) {
            return $project->environments()->get();
        }

        if (!$this->canSeeProject($user, $project)) {
            return new Collection;
        }

        return $project->environments()->whereIn('id', $this->granted($user, $project->team)['environments'])->get();
    }

    public function hidesServer(User $user, Server $server): bool
    {
        return $server->team !== null && $this->membership($user, $server->team)['member'] && !$this->canSeeServer($user, $server);
    }

    public function hidesApplication(User $user, Application $application): bool
    {
        return $application->team !== null && $this->membership($user, $application->team)['member'] && !$this->canSeeApplication($user, $application);
    }

    public function hidesDatabase(User $user, ManagedDatabase $database): bool
    {
        return $database->team !== null && $this->membership($user, $database->team)['member'] && !$this->canSeeDatabase($user, $database);
    }

    public function hidesProject(User $user, Project $project): bool
    {
        return $project->team !== null && $this->membership($user, $project->team)['member'] && !$this->canSeeProject($user, $project);
    }

    public function grant(Team $team, User $user, Server|Application|Project|Environment $subject): void
    {
        AccessGrant::query()->firstOrCreate([
            'team_id' => $team->getKey(),
            'user_id' => $user->getKey(),
            'server_id' => $subject instanceof Server ? $subject->getKey() : null,
            'application_id' => $subject instanceof Application ? $subject->getKey() : null,
            'project_id' => $subject instanceof Project ? $subject->getKey() : null,
            'environment_id' => $subject instanceof Environment ? $subject->getKey() : null,
        ]);

        $this->forget($user, $team);
    }

    /**
     * @param  list<string>  $serverIds
     * @param  list<string>  $applicationIds
     * @param  list<string>  $projectIds
     * @param  list<string>  $environmentIds
     */
    public function sync(Team $team, User $user, bool $everything, array $serverIds, array $applicationIds, array $projectIds = [], array $environmentIds = []): void
    {
        $chosen = [
            'server_id' => $team->servers()->whereIn('id', $serverIds)->pluck('id')->all(),
            'application_id' => $team->applications()->whereIn('id', $applicationIds)->pluck('id')->all(),
            'project_id' => $team->projects()->whereIn('id', $projectIds)->pluck('id')->all(),
            'environment_id' => Environment::query()->whereIn('id', $environmentIds)->whereIn('project_id', $team->projects()->select('id'))->pluck('id')->all(),
        ];

        DB::transaction(function () use ($team, $user, $everything, $chosen): void {
            DB::table('team_user')->where('team_id', $team->getKey())->where('user_id', $user->getKey())->update(['sees_everything' => $everything]);
            AccessGrant::query()->where('team_id', $team->getKey())->where('user_id', $user->getKey())->delete();

            foreach ($chosen as $column => $ids) {
                foreach ($ids as $id) {
                    AccessGrant::query()->create(['team_id' => $team->getKey(), 'user_id' => $user->getKey(), $column => $id]);
                }
            }
        });

        $this->forget($user, $team);
    }

    public function remove(Team $team, User $user): void
    {
        AccessGrant::query()->where('team_id', $team->getKey())->where('user_id', $user->getKey())->delete();

        $this->forget($user, $team);
    }

    /**
     * @return array{everything: bool, servers: list<string>, applications: list<string>, projects: list<string>, environments: list<string>}
     */
    public function summary(Team $team, User $member): array
    {
        return ['everything' => $this->seesEverything($member, $team), ...$this->granted($member, $team)];
    }

    /**
     * @return array{member: bool, everything: bool}
     */
    private function membership(User $user, Team $team): array
    {
        $key = $team->getKey() . ':' . $user->getKey();

        if (!isset($this->memberships[$key])) {
            $row = DB::table('team_user')->where('team_id', $team->getKey())->where('user_id', $user->getKey())->first(['role', 'sees_everything']);

            $this->memberships[$key] = [
                'member' => $row !== null,
                'everything' => $row !== null && ($row->role === TeamRole::Owner->value || ($row->role !== TeamRole::Developer->value && (bool) $row->sees_everything)),
            ];
        }

        return $this->memberships[$key];
    }

    /**
     * @return array{servers: list<string>, applications: list<string>, projects: list<string>, environments: list<string>}
     */
    private function granted(User $user, Team $team): array
    {
        $key = $team->getKey() . ':' . $user->getKey();
        $grants = fn(string $relation, string $column): array => array_values(AccessGrant::query()
            ->where('team_id', $team->getKey())
            ->where('user_id', $user->getKey())
            ->whereHas($relation)
            ->orderBy('id')
            ->pluck($column)
            ->all());

        return $this->grants[$key] ??= [
            'servers' => $grants('server', 'server_id'),
            'applications' => $grants('application', 'application_id'),
            'projects' => $grants('project', 'project_id'),
            'environments' => $grants('environment', 'environment_id'),
        ];
    }

    /**
     * @return list<string>
     */
    private function environmentIds(User $user, Team $team): array
    {
        $key = $team->getKey() . ':' . $user->getKey();
        $granted = $this->granted($user, $team);

        return $this->environments[$key] ??= array_values(array_unique([
            ...$granted['environments'],
            ...Environment::query()->whereIn('project_id', $granted['projects'])->pluck('id')->all(),
        ]));
    }

    /**
     * @return list<string>
     */
    private function projectIds(User $user, Team $team): array
    {
        $key = $team->getKey() . ':' . $user->getKey();
        $granted = $this->granted($user, $team);

        return $this->reached[$key] ??= array_values(array_unique([
            ...$granted['projects'],
            ...Environment::query()->whereIn('id', $granted['environments'])->pluck('project_id')->all(),
        ]));
    }

    private function forget(User $user, Team $team): void
    {
        $key = $team->getKey() . ':' . $user->getKey();

        unset($this->memberships[$key], $this->grants[$key], $this->environments[$key], $this->reached[$key]);
    }
}
