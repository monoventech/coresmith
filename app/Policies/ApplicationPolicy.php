<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Ability;
use App\Models\Application;
use App\Models\User;

final class ApplicationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->teams()->exists();
    }

    public function view(User $user, Application $application): bool
    {
        return $user->hasTeamAbility($application->team, Ability::ViewApplication);
    }

    public function create(User $user): bool
    {
        return $user->teams()->exists();
    }

    public function update(User $user, Application $application): bool
    {
        return $user->hasTeamAbility($application->team, Ability::UpdateApplication);
    }

    public function delete(User $user, Application $application): bool
    {
        return $user->hasTeamAbility($application->team, Ability::DeleteApplication);
    }

    public function deploy(User $user, Application $application): bool
    {
        return $user->hasTeamAbility($application->team, Ability::DeployApplication);
    }

    public function viewLogs(User $user, Application $application): bool
    {
        return $user->hasTeamAbility($application->team, Ability::ViewApplicationLogs);
    }

    public function manageEnvironment(User $user, Application $application): bool
    {
        return $user->hasTeamAbility($application->team, Ability::ManageApplicationEnv);
    }
}
