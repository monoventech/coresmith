<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Ability;
use App\Enums\TeamRole;
use App\Models\ManagedDatabase;
use App\Models\User;
use App\Services\Teams\TeamAccess;
use Illuminate\Auth\Access\Response;

final class ManagedDatabasePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->teams()->exists();
    }

    public function view(User $user, ManagedDatabase $managedDatabase): Response|bool
    {
        return $this->within($user, $managedDatabase, Ability::ViewDatabase);
    }

    public function create(User $user): bool
    {
        return $user->hasAbilityInAnyTeam(Ability::CreateDatabase);
    }

    public function update(User $user, ManagedDatabase $managedDatabase): Response|bool
    {
        return $this->within($user, $managedDatabase, Ability::ViewDatabase);
    }

    public function delete(User $user, ManagedDatabase $managedDatabase): Response|bool
    {
        return $this->added($user, $managedDatabase, Ability::DeleteDatabase);
    }

    public function backup(User $user, ManagedDatabase $managedDatabase): Response|bool
    {
        return $this->within($user, $managedDatabase, Ability::BackupDatabase);
    }

    public function restore(User $user, ManagedDatabase $managedDatabase): Response|bool
    {
        return $this->added($user, $managedDatabase, Ability::RestoreDatabase);
    }

    public function viewCredentials(User $user, ManagedDatabase $managedDatabase): Response|bool
    {
        return $this->within($user, $managedDatabase, Ability::ViewDatabaseCredentials);
    }

    private function added(User $user, ManagedDatabase $managedDatabase, Ability $ability): Response|bool
    {
        $allowed = $this->within($user, $managedDatabase, $ability);

        if ($allowed !== true) {
            return $allowed;
        }

        return $user->roleIn($managedDatabase->team) !== TeamRole::Developer || $managedDatabase->addedBy($user);
    }

    private function within(User $user, ManagedDatabase $managedDatabase, Ability $ability): Response|bool
    {
        return app(TeamAccess::class)->hidesDatabase($user, $managedDatabase) ? Response::denyAsNotFound() : $user->hasTeamAbility($managedDatabase->team, $ability);
    }
}
