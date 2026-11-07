<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Ability;
use App\Models\Deployment;
use App\Models\User;

final class DeploymentPolicy
{
    public function view(User $user, Deployment $deployment): bool
    {
        return $user->hasTeamAbility($deployment->application?->team, Ability::ViewApplicationLogs);
    }

    public function create(User $user, Deployment $deployment): bool
    {
        return $user->hasTeamAbility($deployment->application?->team, Ability::DeployApplication);
    }
}
