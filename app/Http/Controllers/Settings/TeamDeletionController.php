<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Exceptions\TeamDeletionRefused;
use App\Http\Controllers\Controller;
use App\Services\Teams\TeamDeletion;
use App\Services\Teams\TeamUrls;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

final class TeamDeletionController extends Controller
{
    public function destroy(Request $request, TeamDeletion $deletion, TeamUrls $urls): Response
    {
        $team = $this->currentTeam();

        abort_if($team === null, 404);

        $this->authorize('delete', $team);

        $typed = $request->validate(
            ['name' => ['required', 'string', 'max:255']],
            ['name.required' => "Type {$team->name} to delete it."],
        )['name'];

        if ($typed !== $team->name) {
            throw ValidationException::withMessages(['name' => "That isn't the team's name. Type {$team->name} exactly to delete it."]);
        }

        try {
            $deletion->delete($team, $request->user());
        } catch (TeamDeletionRefused $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $next = $request->user()->teams()->orderBy('teams.name')->orderBy('teams.id')->first();

        if ($next === null) {
            $request->session()->flash('team_deleted', true);

            return Inertia::location($urls->withoutTeam('start', $request));
        }

        $request->user()->forceFill(['current_team_id' => $next->getKey()])->save();
        $request->session()->flash('success', "Deleted {$team->name}.");

        return Inertia::location($urls->home($next, $request));
    }
}
