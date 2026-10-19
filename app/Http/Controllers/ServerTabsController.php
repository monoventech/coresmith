<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Server;
use App\Services\Panel\ActivityFeed;
use App\Services\Panel\ServerPresenter;
use App\Services\Server\FirewallConfigurator;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Activitylog\Models\Activity;

final class ServerTabsController extends Controller
{
    public const ISOLATION_LAYERS = [
        'Linux user separation',
        'systemd sandboxing (NoNewPrivileges, ProtectSystem, PrivateTmp)',
        'File permission enforcement',
        'Root-owned env, injected via systemd',
        'Dedicated database credentials',
        'Memory limits per app (MemoryMax)',
        'Non-overlapping port blocks',
        'Per-app Caddy routing',
        'Separate log files',
    ];

    public const HARDENING = [
        'SSH key-only, modern ciphers (curve25519, ed25519)',
        'fail2ban active, SSH jail after 5 failures',
        'Kernel hardening sysctls applied',
        'AppArmor checked for profiles in complain mode',
        'Caddy catch-all returns 444 for unknown hosts',
        'Admin API bound to localhost, verified unreachable',
        'Automatic OS security patches',
    ];

    public function hardening(Request $request, Server $server, ServerPresenter $presenter): Response
    {
        $this->authorize('view', $server);

        return Inertia::render('servers/Hardening', [
            'server' => $presenter->header($server, $request->user()),
            'isolation' => self::ISOLATION_LAYERS,
            'hardening' => self::HARDENING,
            'firewall' => [
                'open' => (new FirewallConfigurator)->requiredPorts($server->ssh_port),
                'ssh_port' => $server->ssh_port,
                'changes' => Activity::query()
                    ->where('subject_type', $server->getMorphClass())
                    ->where('subject_id', $server->getKey())
                    ->where('description', 'firewall_rule_changed')
                    ->latest('id')
                    ->limit(25)
                    ->get()
                    ->map(fn(Activity $activity): array => [
                        'id' => $activity->id,
                        'action' => $activity->properties['action'] ?? null,
                        'port' => $activity->properties['port'] ?? null,
                        'from' => $activity->properties['from'] ?? null,
                        'at' => $activity->created_at?->toIso8601String(),
                    ])->values(),
            ],
            'access' => [
                'ssh' => $server->ssh_user . '@' . $server->ip_address . ':' . $server->ssh_port,
                'fingerprint' => $server->host_key_fingerprint,
                'public_key' => $server->ssh_public_key,
                'audit_logging' => (bool) $server->audit_logging_enabled,
            ],
        ]);
    }

    public function activity(Request $request, Server $server, ServerPresenter $presenter, ActivityFeed $feed): Response
    {
        $this->authorize('view', $server);

        return Inertia::render('servers/Activity', [
            'server' => $presenter->header($server, $request->user()),
            'activity' => $feed->forServer($server),
        ]);
    }
}
