<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\Deployment;
use App\Models\Server;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class ApplicationDeploymentsController extends Controller
{
    public function index(Request $request, Server $server, Application $application): Response
    {
        $this->authorize('view', $application);
        abort_unless($application->server_id === $server->id, 404);

        return Inertia::render('applications/Deployments', [
            'server' => ['id' => $server->id, 'name' => $server->name],
            'application' => ['id' => $application->id, 'name' => $application->name],
            'deployments' => $application->deployments()->latest()->limit(50)->get()
                ->map(fn (Deployment $deployment): array => [
                    'id' => $deployment->id,
                    'status' => $deployment->status->value,
                    'commit_hash' => $deployment->commit_hash,
                    'commit_message' => $deployment->commit_message,
                    'started_at' => $deployment->started_at?->toIso8601String(),
                    'finished_at' => $deployment->finished_at?->toIso8601String(),
                    'href' => route('servers.applications.deployments.show', [$server, $application, $deployment]),
                ])->values(),
            'can' => [
                'deploy' => $request->user()->can('deploy', $application),
                'update' => $request->user()->can('update', $application),
                'manageEnvironment' => $request->user()->can('manageEnvironment', $application),
            ],
        ]);
    }

    public function features(Request $request, Server $server, Application $application): Response
    {
        $this->authorize('view', $application);
        abort_unless($application->server_id === $server->id, 404);

        return Inertia::render('applications/Features', [
            'server' => ['id' => $server->id, 'name' => $server->name],
            'application' => [
                'id' => $application->id,
                'name' => $application->name,
                'power_state' => $application->power_state?->value,
                'toggles' => (object) ((array) $application->toggles),
                'processes' => $application->processes,
                'cron_jobs' => $application->cron_jobs,
            ],
            'can' => [
                'update' => $request->user()->can('update', $application),
                'manageEnvironment' => $request->user()->can('manageEnvironment', $application),
            ],
        ]);
    }
}
