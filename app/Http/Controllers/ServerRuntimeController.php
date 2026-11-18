<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ServerStatus;
use App\Jobs\UpdateServerRuntime;
use App\Models\Application;
use App\Models\Server;
use App\Services\Cloud\Tasks\CloudTask;
use App\Services\Runtime\Catalog;
use App\Services\Server\ServerRuntimes;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class ServerRuntimeController extends Controller
{
    public function update(Request $request, Server $server, ServerRuntimes $runtimes, Catalog $catalog): RedirectResponse
    {
        $this->authorize('update', $server);

        $validated = $request->validate([
            'runtime' => ['required', 'string', Rule::in(Catalog::RUNTIMES)],
            'version' => ['required', 'string', 'max:32', 'regex:/^[0-9A-Za-z._-]+$/'],
            'applications' => ['required', 'array', 'min:1'],
            'applications.*' => ['required', 'string'],
        ], [
            'applications.required' => 'Pick at least one app to deploy on the new version.',
            'applications.min' => 'Pick at least one app to deploy on the new version.',
        ]);

        $label = $catalog->label($validated['runtime']);
        $to = $runtimes->newestPatch($validated['runtime'], $validated['version']);

        if ($to === null) {
            return back()->with('error', "{$label} {$validated['version']} is already the newest patch Coresmith knows of.");
        }

        $refusal = match (true) {
            $server->status !== ServerStatus::Active => 'This server is not ready yet, so nothing was installed.',
            (UpdateServerRuntime::state($server)['state'] ?? null) === 'installing' => 'Coresmith is already installing a runtime on this server. Try again once it finishes.',
            default => CloudTask::refusal($server, 'Update'),
        };

        if ($refusal !== null) {
            return back()->with('error', $refusal);
        }

        $applications = $runtimes->movable($server, $request->user(), $validated['runtime'], $validated['version'])
            ->whereIn('id', $validated['applications'])
            ->map(fn(Application $application): string => $application->id)
            ->values()
            ->all();

        if ($applications === []) {
            return back()->with('error', "None of those apps can move to {$label} {$to}.");
        }

        $job = new UpdateServerRuntime($server, $validated['runtime'], $validated['version'], $to, $applications, $request->user());
        $job->remember('installing');
        dispatch($job);

        return back()->with('success', "Installing {$label} {$to}. The apps deploy once it's in.");
    }
}
