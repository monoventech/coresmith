<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Team;
use App\Models\TeamInvitation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class TeamInvitationController extends Controller
{
    public function destroy(Request $request, Team $team, TeamInvitation $invitation): RedirectResponse
    {
        $this->authorize('manageMembers', $team);

        abort_unless($invitation->team_id === $team->getKey() && $invitation->accepted_at === null, 404);

        $invitation->delete();

        activity()->performedOn($team)->causedBy($request->user())->withProperties(['email' => $invitation->email])->log('invitation_revoked');

        return back()->with('success', 'Invitation revoked.');
    }
}
