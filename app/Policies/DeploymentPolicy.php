<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Ability;
use App\Models\Deployment;
use App\Models\User;
use App\Services\Teams\TeamAccess;
use Illuminate\Auth\Access\Response;

final class DeploymentPolicy
{
    public function view(User $user, Deployment $deployment): Response|bool
    {
        return $this->within($user, $deployment, Ability::ViewApplicationLogs);
    }

    public function create(User $user, Deployment $deployment): Response|bool
    {
        return $this->within($user, $deployment, Ability::DeployApplication);
    }

    private function within(User $user, Deployment $deployment, Ability $ability): Response|bool
    {
        return $deployment->application !== null && app(TeamAccess::class)->hidesApplication($user, $deployment->application) ? Response::denyAsNotFound() : $user->hasTeamAbility($deployment->application?->team, $ability);
    }
}
