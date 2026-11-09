<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Server;
use App\Services\Database\SharedRedis;
use App\Services\Panel\ActivityFeed;
use App\Services\Panel\ServerPresenter;
use App\Services\Server\FirewallConfigurator;
use Illuminate\Http\RedirectResponse;
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
        self::DEDICATED_CREDENTIALS,
        'Memory limits per app (MemoryMax)',
        'Non-overlapping port blocks',
        'Per-app Caddy routing',
        'Separate log files',
    ];

    public const DEDICATED_CREDENTIALS = 'Dedicated database credentials';

    public const DEDICATED_CREDENTIALS_EXCEPT_SHARED_REDIS = 'Dedicated database credentials, except the shared Redis on port 6379';

    public const ADMIN_API_ON_LOCALHOST = 'Admin API bound to localhost, verified unreachable';

    public const ADMIN_API_ON_SOCKET = 'Admin API on a socket only Caddy and root can open';

    public const HARDENING = [
        'SSH key-only, modern ciphers (curve25519, ed25519)',
        'fail2ban active, SSH jail after 5 failures',
        'Kernel hardening sysctls applied',
        'AppArmor checked for profiles in complain mode',
        'Caddy catch-all returns 444 for unknown hosts',
        self::ADMIN_API_ON_LOCALHOST,
        'Automatic OS security patches',
    ];

    public function hardening(Request $request, Server $server, ServerPresenter $presenter, SharedRedis $sharedRedis): Response|RedirectResponse
    {
        if ($server->trashed()) {
            return $this->destroyedServer($server);
        }

        $this->authorize('view', $server);

        return Inertia::render('servers/Hardening', [
            'server' => $presenter->header($server, $request->user()),
            'isolation' => $sharedRedis->state($server) === null
                ? self::ISOLATION_LAYERS
                : array_map(fn(string $layer): string => $layer === self::DEDICATED_CREDENTIALS ? self::DEDICATED_CREDENTIALS_EXCEPT_SHARED_REDIS : $layer, self::ISOLATION_LAYERS),
            'hardening' => $server->caddyAdminOnSocket()
                ? array_map(fn(string $item): string => $item === self::ADMIN_API_ON_LOCALHOST ? self::ADMIN_API_ON_SOCKET : $item, self::HARDENING)
                : self::HARDENING,
            'admin_move_error' => $server->caddyAdminOnSocket() ? null : (((array) $server->meta)['caddy_admin_error'] ?? null),
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
                'ssh' => $server->sshAddress(),
                'fingerprint' => $server->host_key_fingerprint,
                'public_key' => $server->ssh_public_key,
                'audit_logging' => (bool) $server->audit_logging_enabled,
            ],
        ]);
    }

    public function activity(Request $request, Server $server, ServerPresenter $presenter, ActivityFeed $feed): Response|RedirectResponse
    {
        if ($server->trashed()) {
            return $this->destroyedServer($server);
        }

        $this->authorize('view', $server);

        return Inertia::render('servers/Activity', [
            'server' => $presenter->header($server, $request->user()),
            'activity' => $feed->forServer($server),
        ]);
    }
}
