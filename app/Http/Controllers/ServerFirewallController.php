<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\ManageFirewallRule;
use App\Models\Server;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class ServerFirewallController extends Controller
{
    public function store(Request $request, Server $server): RedirectResponse
    {
        $this->authorize('manageFirewall', $server);

        $validated = $request->validate([
            'action' => ['required', Rule::in(['allow', 'deny'])],
            'port' => ['required', 'integer', 'min:1', 'max:65535'],
            'from_ip' => ['nullable', 'ip'],
        ]);

        if ($validated['action'] === 'deny' && (int) $validated['port'] === $server->ssh_port) {
            return back()->with('error', $server->runs_coresmith
                ? "That is this machine's SSH port. Denying it would lock you out of the machine."
                : 'That is the SSH port Coresmith uses to reach this server. Denying it would lock you out.');
        }

        if ($validated['action'] === 'deny' && $server->runs_coresmith && in_array((int) $validated['port'], [80, 443], true)) {
            return back()->with('error', "Coresmith runs on this machine, and people reach it through port {$validated['port']}. Denying it would cut everyone off from Coresmith.");
        }

        ManageFirewallRule::dispatch(
            $server,
            $validated['action'],
            (int) $validated['port'],
            $validated['from_ip'] ?? null,
        );

        return back()->with('success', 'Queued. The rule applies once the server acknowledges it.');
    }
}
