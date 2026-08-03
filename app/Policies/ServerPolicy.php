<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Ability;
use App\Models\Server;
use App\Models\User;

final class ServerPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->teams()->exists();
    }

    public function view(User $user, Server $server): bool
    {
        return $user->hasTeamAbility($server->team, Ability::ViewServer);
    }

    public function create(User $user): bool
    {
        return $user->teams()->exists();
    }

    public function update(User $user, Server $server): bool
    {
        return $user->hasTeamAbility($server->team, Ability::UpdateServer);
    }

    public function delete(User $user, Server $server): bool
    {
        return $user->hasTeamAbility($server->team, Ability::DeleteServer);
    }

    public function viewCredentials(User $user, Server $server): bool
    {
        return $user->hasTeamAbility($server->team, Ability::ViewServerCredentials);
    }

    public function manageFirewall(User $user, Server $server): bool
    {
        return $user->hasTeamAbility($server->team, Ability::ManageFirewall);
    }
}
