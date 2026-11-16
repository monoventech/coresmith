<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Teams;

use App\Enums\TeamRole;
use App\Models\AccessGrant;
use App\Models\Application;
use App\Models\ManagedDatabase;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

final class TeamAccess
{
    /** @var array<string, array{member: bool, everything: bool}> */
    private array $memberships = [];

    /** @var array<string, array{servers: list<string>, applications: list<string>, databases: list<string>, projects: list<string>, withheld_applications: list<string>, withheld_databases: list<string>}> */
    private array $grants = [];

    /** @var array<string, list<string>> */
    private array $reached = [];

    /** @var array<string, list<string>> */
    private array $developers = [];

    public function seesEverything(User $user, Team $team): bool
    {
        return $this->membership($user, $team)['everything'];
    }

    public function seesAllOf(User $user, Team $team): bool
    {
        return $this->membership($user, $team)['owner'] || ($this->seesEverything($user, $team) && $this->developerIds($user, $team) === []);
    }

    public function servers(User $user, Team $team): HasMany
    {
        $servers = $team->servers();

        if ($this->seesEverything($user, $team)) {
            return $servers;
        }

        $granted = $this->granted($user, $team)['servers'];

        if ($this->membership($user, $team)['developer']) {
            $servers->where(fn($query) => $query->whereIn('id', $granted)->orWhere('created_by', $user->getKey()));

            return $servers;
        }

        $servers->whereIn('id', $granted);

        return $servers;
    }

    public function applications(User $user, Team $team): HasMany
    {
        $applications = $team->applications();

        if ($this->seesAllOf($user, $team)) {
            return $applications;
        }

        $granted = $this->granted($user, $team);

        return $applications->where(fn($query) => $this->reach($query, $user, $team, $granted['applications'], $granted['withheld_applications']));
    }

    public function databases(User $user, Team $team): HasMany
    {
        $databases = $team->databases();

        if ($this->seesAllOf($user, $team)) {
            return $databases;
        }

        $granted = $this->granted($user, $team);

        return $databases->where(fn($query) => $this->reach($query, $user, $team, $granted['databases'], $granted['withheld_databases']));
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

    public function applicationsOn(User $user, Server $server): HasMany
    {
        $applications = $server->applications();
        $team = $server->team;

        if ($team === null || $this->seesAllOf($user, $team)) {
            return $applications;
        }

        return $applications->whereIn('id', $this->applications($user, $team)->select('id'));
    }

    public function databasesOn(User $user, Server $server): HasMany
    {
        $databases = $server->databases();
        $team = $server->team;

        if ($team === null || $this->seesAllOf($user, $team)) {
            return $databases;
        }

        return $databases->whereIn('id', $this->databases($user, $team)->select('id'));
    }

    public function canSeeServer(User $user, Server $server): bool
    {
        $team = $server->team;

        if ($team === null || !$this->membership($user, $team)['member']) {
            return false;
        }

        return $this->seesEverything($user, $team)
            || in_array($server->getKey(), $this->granted($user, $team)['servers'], true)
            || ($this->membership($user, $team)['developer'] && $server->addedBy($user));
    }

    public function canSeeApplication(User $user, Application $application): bool
    {
        $team = $application->team;

        if ($team === null || !$this->membership($user, $team)['member']) {
            return false;
        }

        $granted = $this->granted($user, $team);

        return $this->reaches($application, $user, $team, $granted['applications'], $granted['withheld_applications']);
    }

    public function canSeeDatabase(User $user, ManagedDatabase $database): bool
    {
        $team = $database->team;

        if ($team === null || !$this->membership($user, $team)['member']) {
            return false;
        }

        $granted = $this->granted($user, $team);

        return $this->reaches($database, $user, $team, $granted['databases'], $granted['withheld_databases']);
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

        if ($this->seesEverything($user, $team) || in_array($project->getKey(), $this->ownProjectIds($user, $team), true)) {
            return true;
        }

        $granted = $this->granted($user, $team);

        return in_array($project->getKey(), $granted['projects'], true)
            && !$project->applications()->whereIn('id', $granted['withheld_applications'])->exists()
            && !$project->databases()->whereIn('id', $granted['withheld_databases'])->exists();
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

    public function grant(Team $team, User $user, Server|Application|ManagedDatabase|Project $subject): void
    {
        AccessGrant::query()->firstOrCreate([
            'team_id' => $team->getKey(),
            'user_id' => $user->getKey(),
            'server_id' => $subject instanceof Server ? $subject->getKey() : null,
            'application_id' => $subject instanceof Application ? $subject->getKey() : null,
            'database_id' => $subject instanceof ManagedDatabase ? $subject->getKey() : null,
            'project_id' => $subject instanceof Project ? $subject->getKey() : null,
        ]);

        $this->forget($user, $team);
    }

    /**
     * @param  list<string>  $serverIds
     * @param  list<string>  $applicationIds
     * @param  list<string>  $projectIds
     * @param  list<string>  $databaseIds
     * @param  list<string>  $withheldApplicationIds
     * @param  list<string>  $withheldDatabaseIds
     */
    public function sync(Team $team, User $user, bool $everything, array $serverIds, array $applicationIds, array $projectIds = [], array $databaseIds = [], array $withheldApplicationIds = [], array $withheldDatabaseIds = []): void
    {
        $chosen = [
            'server_id' => $team->servers()->whereIn('id', $serverIds)->pluck('id')->all(),
            'application_id' => $team->applications()->whereIn('id', $applicationIds)->pluck('id')->all(),
            'database_id' => $team->databases()->whereIn('id', $databaseIds)->pluck('id')->all(),
            'project_id' => $team->projects()->whereIn('id', $projectIds)->pluck('id')->all(),
        ];
        $under = fn($query) => $query->where(fn($shared) => $shared
            ->when(!$this->membership($user, $team)['developer'], fn($servers) => $servers->whereIn('server_id', $chosen['server_id']))
            ->orWhereIn('project_id', $chosen['project_id']));
        $withheld = [
            'application_id' => $team->applications()->whereIn('id', $withheldApplicationIds)->where($under)->whereNotIn('id', $chosen['application_id'])->pluck('id')->all(),
            'database_id' => $team->databases()->whereIn('id', $withheldDatabaseIds)->where($under)->whereNotIn('id', $chosen['database_id'])->pluck('id')->all(),
        ];

        DB::transaction(function () use ($team, $user, $everything, $chosen, $withheld): void {
            DB::table('team_user')->where('team_id', $team->getKey())->where('user_id', $user->getKey())->update(['sees_everything' => $everything]);
            AccessGrant::query()->where('team_id', $team->getKey())->where('user_id', $user->getKey())->delete();

            foreach ([[$chosen, false], [$withheld, true]] as [$subjects, $held]) {
                foreach ($subjects as $column => $ids) {
                    foreach ($ids as $id) {
                        AccessGrant::query()->create(['team_id' => $team->getKey(), 'user_id' => $user->getKey(), $column => $id, 'withheld' => $held]);
                    }
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
     * @return array{everything: bool, servers: list<string>, applications: list<string>, databases: list<string>, projects: list<string>, withheld_applications: list<string>, withheld_databases: list<string>}
     */
    public function summary(Team $team, User $member): array
    {
        return ['everything' => $this->seesEverything($member, $team), ...$this->granted($member, $team)];
    }

    /**
     * @return array{member: bool, owner: bool, everything: bool, developer: bool}
     */
    private function membership(User $user, Team $team): array
    {
        $key = $team->getKey() . ':' . $user->getKey();

        if (!isset($this->memberships[$key])) {
            $row = DB::table('team_user')->where('team_id', $team->getKey())->where('user_id', $user->getKey())->first(['role', 'sees_everything']);

            $this->memberships[$key] = [
                'member' => $row !== null,
                'owner' => $row !== null && $row->role === TeamRole::Owner->value,
                'everything' => $row !== null && ($row->role === TeamRole::Owner->value || ($row->role !== TeamRole::Developer->value && (bool) $row->sees_everything)),
                'developer' => $row !== null && $row->role === TeamRole::Developer->value,
            ];
        }

        return $this->memberships[$key];
    }

    /**
     * @return array{servers: list<string>, applications: list<string>, databases: list<string>, projects: list<string>, withheld_applications: list<string>, withheld_databases: list<string>}
     */
    private function granted(User $user, Team $team): array
    {
        $key = $team->getKey() . ':' . $user->getKey();
        $grants = fn(string $relation, string $column, bool $withheld = false): array => array_values(AccessGrant::query()
            ->where('team_id', $team->getKey())
            ->where('user_id', $user->getKey())
            ->where('withheld', $withheld)
            ->whereHas($relation)
            ->orderBy('id')
            ->pluck($column)
            ->all());

        return $this->grants[$key] ??= [
            'servers' => $grants('server', 'server_id'),
            'applications' => $grants('application', 'application_id'),
            'databases' => $grants('database', 'database_id'),
            'projects' => $grants('project', 'project_id'),
            'withheld_applications' => $grants('application', 'application_id', true),
            'withheld_databases' => $grants('database', 'database_id', true),
        ];
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
            ...$this->ownProjectIds($user, $team),
        ]));
    }

    /**
     * @return list<string>
     */
    private function ownProjectIds(User $user, Team $team): array
    {
        if (!$this->membership($user, $team)['developer']) {
            return [];
        }

        return array_values(Project::query()->where('team_id', $team->getKey())->where('created_by', $user->getKey())->pluck('id')->all());
    }

    /**
     * @param  list<string>  $granted
     * @param  list<string>  $withheld
     */
    private function reach(Builder $query, User $user, Team $team, array $granted, array $withheld): void
    {
        $developer = $this->membership($user, $team)['developer'];
        $everything = $this->seesEverything($user, $team);
        $servers = $developer ? [] : $this->granted($user, $team)['servers'];
        $projects = $this->projectIds($user, $team);
        $others = $this->developerIds($user, $team);

        $query->whereIn('id', $granted)
            ->when($developer, fn(Builder $own) => $own->orWhere('created_by', $user->getKey()))
            ->orWhere(fn(Builder $open) => $open
                ->where(fn(Builder $theirs) => $theirs->whereNull('created_by')->orWhereNotIn('created_by', $others))
                ->when(!$everything, fn(Builder $shared) => $shared
                    ->whereNotIn('id', $withheld)
                    ->where(fn(Builder $under) => $under->whereIn('server_id', $servers)->orWhereIn('project_id', $projects))));
    }

    /**
     * @param  list<string>  $granted
     * @param  list<string>  $withheld
     */
    private function reaches(Application|ManagedDatabase $subject, User $user, Team $team, array $granted, array $withheld): bool
    {
        $membership = $this->membership($user, $team);

        if ($membership['owner'] || in_array($subject->getKey(), $granted, true) || ($membership['developer'] && $subject->addedBy($user))) {
            return true;
        }

        $creator = $subject->getAttribute('created_by');

        if ($creator !== null && in_array($creator, $this->developerIds($user, $team), true)) {
            return false;
        }

        if ($membership['everything']) {
            return true;
        }

        return !in_array($subject->getKey(), $withheld, true)
            && ((!$membership['developer'] && in_array($subject->getAttribute('server_id'), $this->granted($user, $team)['servers'], true))
                || in_array($subject->getAttribute('project_id'), $this->projectIds($user, $team), true));
    }

    /**
     * @return list<string>
     */
    private function developerIds(User $user, Team $team): array
    {
        $key = $team->getKey() . ':' . $user->getKey();

        return $this->developers[$key] ??= array_values(DB::table('team_user')
            ->where('team_id', $team->getKey())
            ->where('role', TeamRole::Developer->value)
            ->where('user_id', '!=', $user->getKey())
            ->pluck('user_id')
            ->all());
    }

    private function forget(User $user, Team $team): void
    {
        $key = $team->getKey() . ':' . $user->getKey();

        unset($this->memberships[$key], $this->grants[$key], $this->reached[$key], $this->developers[$key]);
    }
}
