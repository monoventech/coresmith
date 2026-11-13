<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Ability;
use App\Enums\TeamRole;
use App\Models\Application;
use App\Models\User;
use App\Services\Teams\TeamAccess;
use Illuminate\Auth\Access\Response;

final class ApplicationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->teams()->exists();
    }

    public function view(User $user, Application $application): Response|bool
    {
        return $this->within($user, $application, Ability::ViewApplication);
    }

    public function create(User $user): bool
    {
        return $user->hasAbilityInAnyTeam(Ability::CreateApplication);
    }

    public function update(User $user, Application $application): Response|bool
    {
        return $this->added($user, $application, Ability::UpdateApplication);
    }

    public function delete(User $user, Application $application): Response|bool
    {
        return $this->added($user, $application, Ability::DeleteApplication);
    }

    public function deploy(User $user, Application $application): Response|bool
    {
        return $this->within($user, $application, Ability::DeployApplication);
    }

    public function viewLogs(User $user, Application $application): Response|bool
    {
        return $this->within($user, $application, Ability::ViewApplicationLogs);
    }

    public function manageEnvironment(User $user, Application $application): Response|bool
    {
        return $this->within($user, $application, Ability::ManageApplicationEnv);
    }

    private function added(User $user, Application $application, Ability $ability): Response|bool
    {
        $allowed = $this->within($user, $application, $ability);

        if ($allowed !== true) {
            return $allowed;
        }

        return $user->roleIn($application->team) !== TeamRole::Developer || $application->addedBy($user);
    }

    private function within(User $user, Application $application, Ability $ability): Response|bool
    {
        return app(TeamAccess::class)->hidesApplication($user, $application) ? Response::denyAsNotFound() : $user->hasTeamAbility($application->team, $ability);
    }
}
