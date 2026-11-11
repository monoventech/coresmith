<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Server;
use App\Services\Database\SharedRedis;
use App\Services\Panel\ActivityFeed;
use App\Services\Panel\Formats;
use App\Services\Panel\ServerPresenter;
use App\Services\Server\FirewallConfigurator;
use App\Services\Server\PasswordLogins;
use Carbon\CarbonImmutable;
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

    public const SSH_KEY_ONLY = 'SSH key-only, modern ciphers (curve25519, ed25519)';

    public const SSH_LEFT_TO_OWNER = "SSH as you set it up, and Coresmith's key only works from this machine";

    public const NO_SITE_FOR_UNKNOWN_HOSTS = 'Caddy serves no site for unknown hostnames';

    public const UNKNOWN_HOSTS_CLOSED = 'Caddy closes the connection for unknown hostnames';

    public const HARDENING = [
        self::SSH_KEY_ONLY,
        'fail2ban active, SSH jail after 5 failures',
        'Kernel hardening sysctls applied',
        'AppArmor checked for profiles in complain mode',
        self::NO_SITE_FOR_UNKNOWN_HOSTS,
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
            'hardening' => array_values(array_filter(
                array_map(fn(string $item): string => match (true) {
                    $item === self::ADMIN_API_ON_LOCALHOST && $server->caddyAdminOnSocket() => self::ADMIN_API_ON_SOCKET,
                    $item === self::NO_SITE_FOR_UNKNOWN_HOSTS && $server->unknownHostsClosed() => self::UNKNOWN_HOSTS_CLOSED,
                    $item === self::SSH_KEY_ONLY && $server->sshLeftToOwner() => self::SSH_LEFT_TO_OWNER,
                    default => $item,
                }, self::HARDENING),
                fn(string $item): bool => $item !== self::SSH_KEY_ONLY || $server->sshHardened(),
            )),
            'admin_move_error' => $server->caddyAdminOnSocket() ? null : (((array) $server->meta)['caddy_admin_error'] ?? null),
            'ssh' => [
                'verified' => $server->sshHardened(),
                'owner' => $server->sshLeftToOwner(),
                'passwords_off' => $server->sshLeftToOwner() && $server->sshPasswordsOff(),
                'error' => $server->sshHardened() || $server->sshLeftToOwner() ? null : (((array) $server->meta)['ssh_hardening_error'] ?? null),
                ...$this->passwordLogins($server),
            ],
            'firewall' => [
                'open' => (new FirewallConfigurator)->openedPorts($server),
                'kept_ssh' => FirewallConfigurator::keptSsh($server),
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

    /**
     * @return array{logins: array{password: bool, keyboard: bool, root: bool}|null, source: string|null, lines: list<string>, reload: string, checked: string|null}
     */
    private function passwordLogins(Server $server): array
    {
        $logins = $server->sshLeftToOwner() ? PasswordLogins::fromMeta((array) $server->meta) : null;
        $on = $logins === null || $logins->off() ? null : $logins;
        $checked = ((array) $server->meta)['ssh_passwords_checked_at'] ?? null;

        return [
            'logins' => $on === null ? null : array_combine(['password', 'keyboard', 'root'], array_map(fn(string $login): bool => in_array($login, $on->on, true), ['password', 'keyboard', 'root'])),
            'source' => $on === null ? null : collect($on->on)->map(fn(string $login): ?string => $on->sources[$login] ?? null)->filter()->first(),
            'lines' => $on?->lines() ?? [],
            'reload' => in_array($server->os_name, ['rocky', 'almalinux', 'fedora', 'amzn'], true) ? 'sshd' : 'ssh',
            'checked' => $on === null || !is_string($checked) ? null : Formats::when(CarbonImmutable::parse($checked)),
        ];
    }
}
