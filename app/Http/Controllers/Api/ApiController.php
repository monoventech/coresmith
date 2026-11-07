<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Team;
use Illuminate\Http\Request;

abstract class ApiController extends Controller
{
    protected function team(Request $request): Team
    {
        $team = $request->attributes->get('api_team');

        abort_unless($team instanceof Team, 403);

        return $team;
    }
}
