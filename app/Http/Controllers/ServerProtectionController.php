<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\RestoreProtection;
use App\Models\Server;
use Illuminate\Http\RedirectResponse;

final class ServerProtectionController extends Controller
{
    public function store(Server $server, string $protection): RedirectResponse
    {
        abort_if($server->runs_coresmith && in_array($protection, RestoreProtection::LEFT_TO_OWNER, true), 404);
        $this->authorize('update', $server);

        $server->forceFill(['meta' => [...(array) $server->meta, 'protections_restoring' => [...(array) (((array) $server->meta)['protections_restoring'] ?? []), $protection => now()->toIso8601String()]]])->save();

        RestoreProtection::dispatch($server, $protection);

        return back()->with('success', match ($protection) {
            'kernel' => 'Setting them again.',
            'patches' => 'Turning them back on.',
            'ssh' => 'Turning off password sign-in.',
            'controls', 'unknown_hosts' => 'Closing them now.',
            default => 'Turning it back on.',
        });
    }
}
