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
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Activitylog\Models\Activity;

final class ServerFirewallController extends Controller
{
    public function index(Request $request, Server $server): Response
    {
        $this->authorize('view', $server);

        return Inertia::render('servers/Firewall', [
            'server' => ['id' => $server->id, 'name' => $server->name, 'ssh_port' => $server->ssh_port],
            'changes' => Activity::query()
                ->where('subject_type', $server->getMorphClass())
                ->where('subject_id', $server->getKey())
                ->where('description', 'firewall_rule_changed')
                ->latest('id')
                ->limit(25)
                ->get()
                ->map(fn (Activity $activity): array => [
                    'id' => $activity->id,
                    'action' => $activity->properties['action'] ?? null,
                    'port' => $activity->properties['port'] ?? null,
                    'from' => $activity->properties['from'] ?? null,
                    'at' => $activity->created_at?->toIso8601String(),
                ])->values(),
            'can' => ['manage' => $request->user()->can('manageFirewall', $server)],
        ]);
    }

    public function store(Request $request, Server $server): RedirectResponse
    {
        $this->authorize('manageFirewall', $server);

        $validated = $request->validate([
            'action' => ['required', Rule::in(['allow', 'deny'])],
            'port' => ['required', 'integer', 'min:1', 'max:65535'],
            'from_ip' => ['nullable', 'ip'],
        ]);

        if ($validated['action'] === 'deny' && (int) $validated['port'] === $server->ssh_port) {
            return back()->with('error', 'That is the SSH port Coresmith uses to reach this server. Denying it would lock you out.');
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
