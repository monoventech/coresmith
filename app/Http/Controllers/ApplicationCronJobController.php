<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\PowerState;
use App\Exceptions\CronListRefused;
use App\Http\Requests\Application\CronJobRequest;
use App\Models\Application;
use App\Models\Server;
use App\Services\Panel\CronJobs;
use App\Services\Panel\SmartToggles;
use Illuminate\Http\RedirectResponse;

final class ApplicationCronJobController extends Controller
{
    private const STALE = 'That cron job changed since this page loaded, so nothing was saved. Check the list and try again.';

    public function store(CronJobRequest $request, Server $server, Application $application, CronJobs $cron, SmartToggles $toggles): RedirectResponse
    {
        abort_unless($application->server_id === $server->id, 404);

        $had = $cron->hasScheduler($application);

        try {
            $cron->add($application, $request->entry());
        } catch (CronListRefused $e) {
            return back()->withErrors(['command' => $e->getMessage()])->withInput();
        }

        return back()->with('success', $this->saved($server, $application, $toggles, $had, $cron->hasScheduler($application)));
    }

    public function update(CronJobRequest $request, Server $server, Application $application, string $cronJob, CronJobs $cron, SmartToggles $toggles): RedirectResponse
    {
        abort_unless($application->server_id === $server->id, 404);

        $had = $cron->hasScheduler($application);

        try {
            $updated = $cron->update($application, $cronJob, $request->entry());
        } catch (CronListRefused $e) {
            return back()->withErrors(['command' => $e->getMessage()])->withInput();
        }

        if ($updated === null) {
            return back()->with('error', self::STALE);
        }

        if ($updated['id'] === $cronJob) {
            return back()->with('success', 'Saved.');
        }

        return back()->with('success', $this->saved($server, $application, $toggles, $had, $cron->hasScheduler($application)));
    }

    public function destroy(Server $server, Application $application, string $cronJob, CronJobs $cron, SmartToggles $toggles): RedirectResponse
    {
        $this->authorize('update', $application);
        abort_unless($application->server_id === $server->id, 404);

        try {
            $removed = $cron->remove($application, $cronJob);
        } catch (CronListRefused $e) {
            return back()->with('error', $e->getMessage());
        }

        if ($removed === null) {
            return back()->with('error', self::STALE);
        }

        return back()->with('success', match (true) {
            $this->offered($application, $toggles) && $cron->isScheduler($removed) && !$cron->hasScheduler($application) => 'Removed. The Scheduler toggle is off now.',
            $application->current_release === null || $application->power_state === PowerState::Stopped => 'Removed.',
            default => "Removed. It stops running on {$server->name} in a few seconds.",
        });
    }

    public function sync(Server $server, Application $application, CronJobs $cron): RedirectResponse
    {
        $this->authorize('update', $application);
        abort_unless($application->server_id === $server->id, 404);

        $cron->retry($application);

        return back()->with('success', "Sending the cron jobs to {$server->name} again.");
    }

    private function saved(Server $server, Application $application, SmartToggles $toggles, bool $had, bool $has): string
    {
        $offered = $this->offered($application, $toggles);

        return match (true) {
            $offered && !$had && $has => 'Saved. The Scheduler toggle is on now.',
            $offered && $had && !$has => 'Saved. That is no longer the Laravel scheduler, so the Scheduler toggle is off now.',
            $application->current_release === null => 'Saved. It runs after the first deploy.',
            $application->power_state === PowerState::Stopped => 'Saved. It runs once you start the app.',
            default => "Saved. {$server->name} picks it up in a few seconds, no deployment needed.",
        };
    }

    private function offered(Application $application, SmartToggles $toggles): bool
    {
        return in_array(SmartToggles::SCHEDULER, $toggles->available($application), true);
    }
}
