<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\DatabaseType;
use App\Models\Application;
use App\Models\Server;
use App\Services\Database\AppDatabaseChoice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class ApplicationDatabaseController extends Controller
{
    public function store(Request $request, Server $server, Application $application, AppDatabaseChoice $choice): RedirectResponse
    {
        $this->authorize('manageEnvironment', $application);
        abort_unless($application->server_id === $server->id, 404);

        $validated = $request->validate([
            'database' => ['nullable', 'string', 'required_without:redis', 'regex:/^(new:(postgresql|mysql)|existing:[0-9A-Za-z]{26})$/D'],
            'redis' => ['nullable', 'string', 'required_without:database', 'regex:/^(new|existing:[0-9A-Za-z]{26})$/D'],
        ]);
        $database = $validated['database'] ?? null;
        $redis = $validated['redis'] ?? null;
        $problems = $choice->problems($server, $request->user(), $database, $redis);

        if ($problems !== []) {
            return back()->with('error', array_values($problems)[0]);
        }

        $connected = $choice->apply($application, $database, $redis)[0];
        $engine = match ($connected->type) {
            DatabaseType::Postgresql => 'PostgreSQL',
            DatabaseType::Mysql => 'MySQL',
            default => 'Redis',
        };

        return back()->with('success', str_starts_with((string) ($database ?? $redis), 'new')
            ? "Creating a {$engine} database for {$application->name}. Its settings are in the variables, so deploy once it's ready."
            : "Connected {$application->name} to {$connected->name}. Its settings are in the variables, so deploy to use it.");
    }
}
