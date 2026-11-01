<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\PowerState;
use App\Models\Application;
use App\Models\Server;
use App\Services\Panel\Reverb;
use App\Services\Panel\SmartToggles;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class ApplicationToggleController extends Controller
{
    public function update(Request $request, Server $server, Application $application, string $toggle, SmartToggles $toggles): RedirectResponse
    {
        $this->authorize('update', $application);
        abort_unless($application->server_id === $server->id, 404);
        abort_unless(in_array($toggle, $toggles->available($application), true), 404);

        $on = (bool) $request->validate(['on' => ['required', 'boolean']])['on'];
        $label = $toggles->label($toggle);

        if ($toggle === SmartToggles::MAINTENANCE && $application->power_state === PowerState::Stopped) {
            return back()->with('error', 'The application is stopped, so visitors already see the maintenance page. Start it first.');
        }

        if ($toggles->isOn($application, $toggle) === $on) {
            return back();
        }

        $problem = $toggle === SmartToggles::REVERB && $on ? app(Reverb::class)->problem($application) : null;

        if ($problem !== null) {
            return back()->with('error', $problem);
        }

        $toggles->apply($application, $toggle, $on);

        $message = match (true) {
            $toggle === SmartToggles::MAINTENANCE && $on => 'Maintenance page is on. Visitors see it now, your processes keep running.',
            $toggle === SmartToggles::MAINTENANCE => 'Maintenance page is off. Traffic reaches the app again.',
            $toggle === SmartToggles::AUTO_DEPLOY && $on => "Auto deploy is on, {$application->repository_branch} deploys whenever it changes.",
            $toggle === SmartToggles::AUTO_DEPLOY => 'Auto deploy is off, deploy from this page when you are ready.',
            $toggle === SmartToggles::REVERB && $on => "{$label} starts with the next deploy.",
            $on => "{$label} is starting on {$server->name}, no deployment needed.",
            default => "{$label} stopped. Nothing else was touched.",
        };

        return back()->with('success', 'Saved. ' . $message);
    }
}
