<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\SyncApplicationAddresses;
use App\Models\Application;
use App\Models\Server;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class ApplicationAddressController extends Controller
{
    public function update(Request $request, Server $server, Application $application): RedirectResponse
    {
        abort_unless($application->server_id === $server->getKey(), 404);
        $this->authorize('update', $application);

        $validated = $request->validate([
            'tailnet' => ['required', 'boolean'],
            'preview' => ['required', 'boolean'],
        ]);

        $application->forceFill(['tailnet' => (bool) $validated['tailnet'], 'preview' => (bool) $validated['preview']])->save();

        SyncApplicationAddresses::dispatch($application);

        activity()->performedOn($application)->causedBy($request->user())->withProperties(['tailnet' => $application->tailnet, 'preview' => $application->preview])->log('addresses_changed');

        return back()->with('success', 'Saved.');
    }
}
