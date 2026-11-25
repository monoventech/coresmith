<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\PowerState;
use App\Jobs\RestartAppProcess;
use App\Models\Application;
use App\Models\Server;
use App\Services\Process\ProcessManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class ApplicationProcessController extends Controller
{
    public function restart(Request $request, Server $server, Application $application, string $process, ProcessManager $processes): RedirectResponse
    {
        abort_unless($application->server_id === $server->id, 404);
        $this->authorize('update', $application);
        abort_unless(in_array($process, $processes->processNames($application), true), 404);

        if (($refusal = $this->refusal($application)) !== null) {
            return back()->with('error', $refusal);
        }

        RestartAppProcess::dispatch($application, $process, $request->user());

        return back()->with('success', 'Restarting ' . RestartAppProcess::describe($process) . '.');
    }

    public function restartAll(Request $request, Server $server, Application $application, ProcessManager $processes): RedirectResponse
    {
        abort_unless($application->server_id === $server->id, 404);
        $this->authorize('update', $application);
        abort_if($processes->processNames($application) === [], 404);

        if (($refusal = $this->refusal($application)) !== null) {
            return back()->with('error', $refusal);
        }

        RestartAppProcess::dispatch($application, null, $request->user());

        return back()->with('success', "Restarting every process for {$application->name}.");
    }

    private function refusal(Application $application): ?string
    {
        return match ($application->power_state) {
            PowerState::Stopped => "{$application->name} is stopped. Start it to run its processes again.",
            PowerState::Sleeping => "{$application->name} is asleep. Its processes start on its next visit.",
            default => null,
        };
    }
}
