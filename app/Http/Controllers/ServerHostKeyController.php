<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\SyncAuthorizedKeys;
use App\Models\Server;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class ServerHostKeyController extends Controller
{
    public function accept(Request $request, Server $server): RedirectResponse
    {
        $this->authorize('update', $server);

        $pending = (string) $server->pending_host_key_fingerprint;

        if ($pending === '') {
            return back()->with('error', 'That server has no pending host key to accept.');
        }

        $validated = $request->validate([
            'fingerprint' => ['required', 'string'],
        ]);

        if (!hash_equals($pending, $validated['fingerprint'])) {
            return back()->with('error', 'That fingerprint no longer matches what the server presented. Reload and check it again.');
        }

        $previous = (string) $server->host_key_fingerprint;

        $server->forceFill([
            'host_key_fingerprint' => $pending,
            'pending_host_key_fingerprint' => null,
            'host_key_changed_at' => null,
        ])->save();

        activity()
            ->performedOn($server)
            ->causedBy($request->user())
            ->withProperties(['previous' => $previous, 'accepted' => $pending])
            ->log('ssh_host_key_accepted');

        SyncAuthorizedKeys::dispatch($server);

        return back()->with('success', 'New host key accepted. Coresmith connects to this server again.');
    }

    public function reject(Request $request, Server $server): RedirectResponse
    {
        $this->authorize('update', $server);

        $pending = (string) $server->pending_host_key_fingerprint;

        if ($pending === '') {
            return back()->with('error', 'That server has no pending host key to dismiss.');
        }

        $server->forceFill([
            'pending_host_key_fingerprint' => null,
            'host_key_changed_at' => null,
        ])->save();

        activity()
            ->performedOn($server)
            ->causedBy($request->user())
            ->withProperties(['rejected' => $pending])
            ->log('ssh_host_key_rejected');

        return back()->with('success', 'Dismissed. Coresmith still trusts only the original key, so it won\'t connect until the server presents it again.');
    }
}
