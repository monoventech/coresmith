<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\DeployApplication;
use App\Jobs\RollbackApplication;
use App\Models\Application;
use App\Models\Deployment;
use App\Models\Server;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class DeploymentController extends Controller
{
    public function deploy(Request $request, Server $server, Application $application): RedirectResponse
    {
        $this->authorize('deploy', $application);

        abort_unless($application->server_id === $server->id, 404);

        $deployment = Deployment::create([
            'application_id' => $application->id,
            'triggered_by' => $request->user()?->id,
        ]);

        DeployApplication::dispatch($deployment, now()->format('YmdHis'));

        return back()->with('success', 'Deployment queued.');
    }

    public function rollback(Server $server, Application $application): RedirectResponse
    {
        $this->authorize('deploy', $application);

        abort_unless($application->server_id === $server->id, 404);

        RollbackApplication::dispatch($application);

        return back()->with('success', 'Rolling back to the previous release.');
    }
}
