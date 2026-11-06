<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Billing\CloudSignup;
use App\Services\Billing\TrialHistory;
use App\Services\Teams\TeamUrls;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response;

final class NoTeamController extends Controller
{
    public function show(Request $request, TrialHistory $trials, TeamUrls $urls): InertiaResponse|RedirectResponse
    {
        $user = $request->user();
        $team = $user->teams()->orderBy('teams.name')->orderBy('teams.id')->first();

        if ($team !== null) {
            return redirect()->to($urls->home($team, $request));
        }

        return Inertia::render('auth/NoTeam', [
            'deleted' => (bool) $request->session()->get('team_deleted', false),
            'trial_days' => $trials->used((string) $user->email) ? null : (int) config('coresmith.billing.trial_days', 14),
        ]);
    }

    public function store(Request $request, CloudSignup $signup, TeamUrls $urls): Response
    {
        $user = $request->user();
        $team = $user->teams()->orderBy('teams.name')->orderBy('teams.id')->first();

        if ($team === null) {
            $team = $signup->start($user);
            $request->session()->flash('success', "Started {$team->name}.");
        }

        return Inertia::location($urls->home($team, $request));
    }
}
