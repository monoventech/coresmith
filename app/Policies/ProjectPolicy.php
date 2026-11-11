<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Ability;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use App\Services\Teams\TeamAccess;
use Illuminate\Auth\Access\Response;

final class ProjectPolicy
{
    public function view(User $user, Project $project): Response|bool
    {
        $access = app(TeamAccess::class);

        return $access->hidesProject($user, $project) ? Response::denyAsNotFound() : $access->canSeeProject($user, $project);
    }

    public function create(User $user, Team $team): bool
    {
        return $user->hasTeamAbility($team, Ability::ManageProjects);
    }

    public function manage(User $user, Project $project): Response
    {
        $access = app(TeamAccess::class);

        if ($access->hidesProject($user, $project)) {
            return Response::denyAsNotFound();
        }

        return $user->hasTeamAbility($project->team, Ability::ManageProjects) && $access->seesWholeProject($user, $project)
            ? Response::allow()
            : Response::denyWithStatus(403);
    }
}
