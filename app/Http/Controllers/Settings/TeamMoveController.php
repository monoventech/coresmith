<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Services\Moves\TeamMove;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class TeamMoveController extends Controller
{
    public function store(Request $request, TeamMove $moves): RedirectResponse
    {
        $team = $this->billingTeam();
        $paste = 'Paste the move code from Settings, General on your panel.';
        $validated = $request->validate(['code' => ['required', 'string', 'max:4000']], ['code.required' => $paste, 'code.string' => $paste, 'code.max' => $paste]);

        $moves->start($team, $request->user(), $validated['code']);

        return back()->with('success', 'Saved. Coresmith Cloud has paused this team. Upload the move file on your panel to finish.');
    }

    public function show(): Response
    {
        $team = $this->billingTeam();

        abort_if($team->moving_at === null || $team->move_file === null, 404);

        return response((string) $team->move_file, 200, [
            'Content-Type' => 'application/json',
            'Content-Disposition' => 'attachment; filename="' . $team->slug . '.coresmith-move"',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function destroy(Request $request, TeamMove $moves): RedirectResponse
    {
        $moves->cancel($this->billingTeam(), $request->user());

        return back()->with('success', 'Saved. Coresmith Cloud manages this team again.');
    }

    private function billingTeam(): Team
    {
        $team = $this->currentTeam();
        abort_if($team === null, 404);
        $this->authorize('manageBilling', $team);

        return $team;
    }
}
