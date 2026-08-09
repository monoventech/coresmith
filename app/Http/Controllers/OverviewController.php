<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\ManagedDatabase;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class OverviewController extends Controller
{
    public function applications(Request $request): Response
    {
        $team = $request->user()->teams()->first();

        abort_if($team === null, 404);

        return Inertia::render('applications/Overview', [
            'applications' => $team->applications()->with('server')->orderBy('name')->get()
                ->map(fn (Application $application): array => [
                    'id' => $application->id,
                    'name' => $application->name,
                    'domain' => $application->domain,
                    'type' => $application->type?->value,
                    'framework' => $application->framework,
                    'status' => $application->status?->value,
                    'power_state' => $application->power_state?->value,
                    'server' => ['id' => $application->server_id, 'name' => $application->server?->name],
                    'href' => route('servers.applications.show', [$application->server_id, $application]),
                ])->values(),
        ]);
    }

    public function databases(Request $request): Response
    {
        $team = $request->user()->teams()->first();

        abort_if($team === null, 404);

        return Inertia::render('databases/Overview', [
            'databases' => ManagedDatabase::query()
                ->where('team_id', $team->getKey())
                ->with('server')
                ->orderBy('name')
                ->get()
                ->map(fn (ManagedDatabase $database): array => [
                    'id' => $database->id,
                    'name' => $database->name,
                    'type' => $database->type?->value,
                    'db_user' => $database->db_user,
                    'server' => ['id' => $database->server_id, 'name' => $database->server?->name],
                    'href' => route('servers.databases.show', [$database->server_id, $database]),
                ])->values(),
        ]);
    }
}
