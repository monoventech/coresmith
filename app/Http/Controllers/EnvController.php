<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\Server;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class EnvController extends Controller
{
    public function update(Request $request, Server $server, Application $application): RedirectResponse
    {
        $this->authorize('manageEnvironment', $application);

        abort_unless($application->server_id === $server->id, 404);

        $validated = $request->validate([
            'env' => ['nullable', 'string', 'max:65535'],
        ]);

        $application->update(['env_variables' => $validated['env'] ?? null]);

        activity()->performedOn($application)->log('environment_updated');

        return back()->with('success', 'Saved. The new values are written at the next deploy.');
    }
}
