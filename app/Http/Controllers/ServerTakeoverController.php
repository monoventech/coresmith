<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ServerStatus;
use App\Jobs\TakeOverServer;
use App\Models\Server;
use Illuminate\Http\RedirectResponse;

final class ServerTakeoverController extends Controller
{
    public function store(Server $server): RedirectResponse
    {
        $this->authorize('update', $server);

        abort_unless($server->status === ServerStatus::Moving && $server->team?->moving_at === null && ($server->takeover['state'] ?? null) === 'failed', 404);

        $server->forceFill(['takeover' => [...(array) $server->takeover, 'state' => 'waiting', 'error' => null]])->save();
        TakeOverServer::dispatch($server);

        return back()->with('success', "Trying {$server->name} again.");
    }
}
