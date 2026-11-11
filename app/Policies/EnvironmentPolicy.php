<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Ability;
use App\Models\Environment;
use App\Models\User;
use App\Services\Teams\TeamAccess;
use Illuminate\Auth\Access\Response;

final class EnvironmentPolicy
{
    public function fill(User $user, Environment $environment): Response
    {
        $access = app(TeamAccess::class);
        $project = $environment->project;

        if ($project === null || $access->hidesProject($user, $project) || !$access->canSeeEnvironment($user, $environment)) {
            return Response::denyAsNotFound();
        }

        return $user->hasTeamAbility($project->team, Ability::ManageProjects) ? Response::allow() : Response::denyWithStatus(403);
    }
}
