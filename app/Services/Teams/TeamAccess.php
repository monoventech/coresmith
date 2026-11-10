<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Teams;

use App\Enums\TeamRole;
use App\Models\AccessGrant;
use App\Models\Application;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

final class TeamAccess
{
    /** @var array<string, array{member: bool, everything: bool}> */
    private array $memberships = [];

    /** @var array<string, array{servers: list<string>, applications: list<string>}> */
    private array $grants = [];

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
        $applications->where(fn($query) => $query->whereIn('server_id', $granted['servers'])->orWhereIn('id', $granted['applications']));

        return $applications;
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

        return in_array($application->getKey(), $granted['applications'], true) || in_array($application->server_id, $granted['servers'], true);
    }

    public function hidesServer(User $user, Server $server): bool
    {
        return $server->team !== null && $this->membership($user, $server->team)['member'] && !$this->canSeeServer($user, $server);
    }

    public function hidesApplication(User $user, Application $application): bool
    {
        return $application->team !== null && $this->membership($user, $application->team)['member'] && !$this->canSeeApplication($user, $application);
    }

    public function grant(Team $team, User $user, Server|Application $subject): void
    {
        AccessGrant::query()->firstOrCreate([
            'team_id' => $team->getKey(),
            'user_id' => $user->getKey(),
            'server_id' => $subject instanceof Server ? $subject->getKey() : null,
            'application_id' => $subject instanceof Application ? $subject->getKey() : null,
        ]);

        $this->forget($user, $team);
    }

    /**
     * @param  list<string>  $serverIds
     * @param  list<string>  $applicationIds
     */
    public function sync(Team $team, User $user, bool $everything, array $serverIds, array $applicationIds): void
    {
        $servers = $team->servers()->whereIn('id', $serverIds)->pluck('id')->all();
        $applications = $team->applications()->whereIn('id', $applicationIds)->pluck('id')->all();

        DB::transaction(function () use ($team, $user, $everything, $servers, $applications): void {
            DB::table('team_user')->where('team_id', $team->getKey())->where('user_id', $user->getKey())->update(['sees_everything' => $everything]);
            AccessGrant::query()->where('team_id', $team->getKey())->where('user_id', $user->getKey())->delete();

            foreach ($servers as $server) {
                AccessGrant::query()->create(['team_id' => $team->getKey(), 'user_id' => $user->getKey(), 'server_id' => $server]);
            }

            foreach ($applications as $application) {
                AccessGrant::query()->create(['team_id' => $team->getKey(), 'user_id' => $user->getKey(), 'application_id' => $application]);
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
     * @return array{everything: bool, servers: list<string>, applications: list<string>}
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
                'everything' => $row !== null && ($row->role === TeamRole::Owner->value || (bool) $row->sees_everything),
            ];
        }

        return $this->memberships[$key];
    }

    /**
     * @return array{servers: list<string>, applications: list<string>}
     */
    private function granted(User $user, Team $team): array
    {
        $key = $team->getKey() . ':' . $user->getKey();

        return $this->grants[$key] ??= [
            'servers' => array_values(AccessGrant::query()->where('team_id', $team->getKey())->where('user_id', $user->getKey())->whereHas('server')->orderBy('id')->pluck('server_id')->all()),
            'applications' => array_values(AccessGrant::query()->where('team_id', $team->getKey())->where('user_id', $user->getKey())->whereHas('application')->orderBy('id')->pluck('application_id')->all()),
        ];
    }

    private function forget(User $user, Team $team): void
    {
        $key = $team->getKey() . ':' . $user->getKey();

        unset($this->memberships[$key], $this->grants[$key]);
    }
}
