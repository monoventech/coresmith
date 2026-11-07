<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Ability;
use App\Models\SourceProvider;
use App\Models\User;

final class SourceProviderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->teams()->exists();
    }

    public function view(User $user, SourceProvider $sourceProvider): bool
    {
        return $user->hasTeamAbility($sourceProvider->team, Ability::ManageProviders);
    }

    public function create(User $user): bool
    {
        return $user->teams()->exists();
    }

    public function update(User $user, SourceProvider $sourceProvider): bool
    {
        return $user->hasTeamAbility($sourceProvider->team, Ability::ManageProviders);
    }

    public function delete(User $user, SourceProvider $sourceProvider): bool
    {
        return $user->hasTeamAbility($sourceProvider->team, Ability::ManageProviders);
    }
}
