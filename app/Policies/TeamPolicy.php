<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Ability;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;

final class TeamPolicy
{
    public function view(User $user, Team $team): bool
    {
        return $user->hasTeamAbility($team, Ability::ViewTeam);
    }

    public function update(User $user, Team $team): bool
    {
        return $user->hasTeamAbility($team, Ability::UpdateTeam);
    }

    public function delete(User $user, Team $team): bool
    {
        return $user->hasTeamAbility($team, Ability::DeleteTeam);
    }

    public function transferOwnership(User $user, Team $team): bool
    {
        return $user->hasTeamAbility($team, Ability::TransferOwnership);
    }

    public function manageBilling(User $user, Team $team): bool
    {
        return $user->hasTeamAbility($team, Ability::ManageBilling);
    }

    public function manageMembers(User $user, Team $team): bool
    {
        return $user->hasTeamAbility($team, Ability::ManageMembers);
    }

    public function shareAccess(User $user, Team $team): bool
    {
        return $user->roleIn($team) === TeamRole::Owner;
    }
}
