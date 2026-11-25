<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ServerStatus;
use App\Http\Requests\Application\UpdateApplicationRuntimeRequest;
use App\Jobs\UpdateServerRuntime;
use App\Models\Application;
use App\Models\Server;
use App\Services\Cloud\Tasks\CloudTask;
use App\Services\Runtime\Catalog;
use App\Services\Server\ServerRuntimes;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class ApplicationRuntimeController extends Controller
{
    public function patch(Request $request, Server $server, Application $application, ServerRuntimes $runtimes, Catalog $catalog): RedirectResponse
    {
        abort_unless($application->server_id === $server->id, 404);
        $this->authorize('update', $application);
        $this->authorize('deploy', $application);

        $runtime = (string) $request->validate([
            'runtime' => ['required', 'string', Rule::in(array_keys($application->runtimeVersions()))],
        ])['runtime'];
        $from = $application->runtimeVersions()[$runtime];
        $label = $catalog->label($runtime);
        $to = $runtimes->newestPatch($runtime, $from);

        if ($to === null) {
            return back()->with('error', "{$label} {$from} is already the newest patch Coresmith knows of.");
        }

        $pin = $runtimes->exactPin($application, $runtime);
        $refusal = match (true) {
            $pin !== null => "{$application->name}'s {$pin} asks for exactly {$label} {$from}. Change it there to move to {$to}.",
            $server->status !== ServerStatus::Active => 'This server is not ready yet, so nothing was installed.',
            (UpdateServerRuntime::state($server)['state'] ?? null) === 'installing' => 'Coresmith is already installing a runtime on this server. Try again once it finishes.',
            default => CloudTask::refusal($server, 'Update'),
        };

        if ($refusal !== null) {
            return back()->with('error', $refusal);
        }

        $job = new UpdateServerRuntime($server, $runtime, $from, $to, [$application->id], $request->user());
        $job->remember('installing');
        dispatch($job);

        return back()->with('success', "Installing {$label} {$to}. {$application->name} deploys once it's in.");
    }

    public function update(UpdateApplicationRuntimeRequest $request, Server $server, Application $application, Catalog $catalog): RedirectResponse
    {
        abort_unless($application->server_id === $server->id, 404);

        $runtime = $request->runtime();
        abort_if($runtime === null, 404);

        $line = $request->line();
        $application->forceFill(['runtime_pins' => $line === null ? null : [$runtime => $line]])->save();

        activity()->performedOn($application)->withProperties(['runtime' => $runtime, 'line' => $line])->log('runtime_chosen');

        return back()->with('success', $line === null
            ? 'Saved. The next deploy uses the version the repository asks for.'
            : 'Saved. The next deploy uses ' . $catalog->label($runtime) . " {$line}.");
    }
}
