<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\Teams\TeamContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class TeamNameController extends Controller
{
    public function update(Request $request, TeamContext $context): RedirectResponse
    {
        $team = $context->teamOrFail();
        $this->authorize('update', $team);

        $name = (string) $request->validate(['name' => ['required', 'string', 'max:255']], ['name.required' => 'Give the team a name.'])['name'];
        $previous = $team->name;

        if ($previous !== $name) {
            $team->forceFill(['name' => $name])->save();
            activity()->performedOn($team)->withProperties(['from' => $previous, 'to' => $name])->log('team_renamed');
        }

        return back()->with('success', 'Saved.');
    }
}
