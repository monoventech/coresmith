<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\PowerState;
use App\Jobs\StartApplication;
use App\Jobs\StopApplication;
use App\Jobs\SyncApplicationRoute;
use App\Models\Application;
use App\Models\Server;
use App\Models\User;
use App\Notifications\ApplicationToggledNotification;
use App\Notifications\Preferences;
use App\Notifications\TeamNotifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class ApplicationPowerController extends Controller
{
    public function stop(Server $server, Application $application): RedirectResponse
    {
        $this->authorize('update', $application);
        abort_unless($application->server_id === $server->id, 404);

        if ($application->power_state === PowerState::Stopped) {
            return back()->with('success', 'That application is already stopped.');
        }

        $application->forceFill(['power_state' => PowerState::Stopped])->save();

        StopApplication::dispatch($application);
        SyncApplicationRoute::dispatch($application);

        activity()->performedOn($application)->log('application_stopped');
        $this->notify($application, 'The app was stopped');

        return back()->with('success', 'Stopping. Visitors will see a maintenance page until you start it again.');
    }

    public function start(Server $server, Application $application): RedirectResponse
    {
        $this->authorize('update', $application);
        abort_unless($application->server_id === $server->id, 404);

        if ($application->power_state === PowerState::Active) {
            return back()->with('success', 'That application is already running.');
        }

        $application->forceFill(['power_state' => PowerState::Active])->save();

        StartApplication::dispatch($application);
        SyncApplicationRoute::dispatch($application);

        activity()->performedOn($application)->log('application_started');
        $this->notify($application, 'The app was started');

        return back()->with('success', 'Starting. The site will be reachable again shortly.');
    }

    public function maintenance(Request $request, Server $server, Application $application): RedirectResponse
    {
        $this->authorize('update', $application);
        abort_unless($application->server_id === $server->id, 404);

        $validated = $request->validate(['enabled' => ['required', 'boolean']]);
        $toggles = (array) $application->toggles;
        $toggles['maintenance_mode'] = $validated['enabled'];

        $application->forceFill(['toggles' => $toggles])->save();

        SyncApplicationRoute::dispatch($application);

        activity()->performedOn($application)->log($validated['enabled'] ? 'maintenance_enabled' : 'maintenance_disabled');
        $this->notify($application, $validated['enabled'] ? 'Maintenance page turned on' : 'Maintenance page turned off');

        return back()->with('success', $validated['enabled']
            ? 'Maintenance page is on. Your processes keep running so you can still debug.'
            : 'Maintenance page is off.');
    }

    private function notify(Application $application, string $change): void
    {
        $actor = auth()->user();

        app(TeamNotifier::class)->send(
            $application->team,
            Preferences::TOGGLE_CHANGES,
            new ApplicationToggledNotification($application, $change, $actor?->name),
            $actor instanceof User ? $actor : null,
        );
    }
}
