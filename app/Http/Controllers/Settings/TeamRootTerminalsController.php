<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Enums\TerminalEndReason;
use App\Http\Controllers\Controller;
use App\Services\Teams\TeamContext;
use App\Services\Terminal\TerminalSessions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class TeamRootTerminalsController extends Controller
{
    public function update(Request $request, TeamContext $context): RedirectResponse
    {
        $team = $context->teamOrFail();
        $this->authorize('manageRootTerminals', $team);

        $on = (bool) $request->validate(['root_terminals' => ['required', 'boolean']], ['root_terminals.required' => 'Choose whether owners can open a root shell.'])['root_terminals'];

        if ((bool) $team->root_terminals !== $on) {
            $team->forceFill(['root_terminals' => $on])->save();
            activity()->performedOn($team)->causedBy($request->user())->log($on ? 'root_terminals_on' : 'root_terminals_off');
        }

        if (!$on) {
            app(TerminalSessions::class)->endRoot($team, TerminalEndReason::RootOff);
        }

        return back()->with('success', 'Saved.');
    }
}
