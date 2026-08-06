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
use Inertia\Inertia;
use Inertia\Response;

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

    public function show(Server $server, Application $application, Deployment $deployment): Response
    {
        $this->authorize('view', $application);

        abort_unless($application->server_id === $server->id, 404);
        abort_unless($deployment->application_id === $application->id, 404);

        return Inertia::render('deployments/Show', [
            'server' => ['id' => $server->id, 'name' => $server->name],
            'application' => ['id' => $application->id, 'name' => $application->name],
            'deployment' => $this->present($deployment),
            'streaming' => $this->streamingAvailable(),
        ]);
    }

    private function streamingAvailable(): bool
    {
        return !in_array((string) config('broadcasting.default'), ['null', 'log', ''], true);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Deployment $deployment): array
    {
        return [
            'id' => $deployment->id,
            'status' => $deployment->status->value,
            'log' => (string) $deployment->log,
            'commit_hash' => $deployment->commit_hash,
            'commit_message' => $deployment->commit_message,
            'started_at' => $deployment->started_at?->toIso8601String(),
            'finished_at' => $deployment->finished_at?->toIso8601String(),
            'created_at' => $deployment->created_at?->toIso8601String(),
        ];
    }

    public function rollback(Server $server, Application $application): RedirectResponse
    {
        $this->authorize('deploy', $application);

        abort_unless($application->server_id === $server->id, 404);

        RollbackApplication::dispatch($application);

        return back()->with('success', 'Rolling back to the previous release.');
    }
}
