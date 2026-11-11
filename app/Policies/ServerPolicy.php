<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Ability;
use App\Models\Server;
use App\Models\User;
use App\Services\Teams\TeamAccess;
use Illuminate\Auth\Access\Response;

final class ServerPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->teams()->exists();
    }

    public function view(User $user, Server $server): Response|bool
    {
        return $this->within($user, $server, Ability::ViewServer);
    }

    public function create(User $user): bool
    {
        return $user->hasAbilityInAnyTeam(Ability::CreateServer);
    }

    public function update(User $user, Server $server): Response|bool
    {
        return $this->within($user, $server, Ability::UpdateServer);
    }

    public function delete(User $user, Server $server): Response|bool
    {
        return $this->within($user, $server, Ability::DeleteServer);
    }

    public function viewCredentials(User $user, Server $server): Response|bool
    {
        return $this->within($user, $server, Ability::ViewServerCredentials);
    }

    public function manageFirewall(User $user, Server $server): Response|bool
    {
        return $this->within($user, $server, Ability::ManageFirewall);
    }

    private function within(User $user, Server $server, Ability $ability): Response|bool
    {
        return app(TeamAccess::class)->hidesServer($user, $server) ? Response::denyAsNotFound() : $user->hasTeamAbility($server->team, $ability);
    }
}
