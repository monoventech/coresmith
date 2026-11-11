<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\Server;
use App\Models\Team;
use App\Services\Teams\TeamAccess;
use Illuminate\Http\Request;

abstract class ApiController extends Controller
{
    protected function team(Request $request): Team
    {
        $team = $request->attributes->get('api_team');

        abort_unless($team instanceof Team, 403);

        return $team;
    }

    protected function seenServer(Request $request, Server $server): void
    {
        abort_unless($server->team_id === $this->team($request)->getKey() && app(TeamAccess::class)->canSeeServer($request->user(), $server), 404);
    }

    protected function seenApplication(Request $request, Application $application): void
    {
        abort_unless($application->team_id === $this->team($request)->getKey() && app(TeamAccess::class)->canSeeApplication($request->user(), $application), 404);
    }
}
