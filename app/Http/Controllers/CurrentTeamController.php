<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Team;
use App\Services\Teams\TeamUrls;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

final class CurrentTeamController extends Controller
{
    public function update(Request $request, TeamUrls $urls): Response
    {
        $validated = $request->validate(['team' => ['required', 'string', 'max:64']]);
        $user = $request->user();
        $team = $user->teams()->whereKey($validated['team'])->first();

        abort_unless($team instanceof Team, 404);

        $user->forceFill(['current_team_id' => $team->getKey()])->save();

        return Inertia::location($urls->home($team, $request));
    }
}
