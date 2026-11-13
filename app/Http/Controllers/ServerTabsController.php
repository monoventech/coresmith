<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Server;
use App\Services\Panel\ActivityFeed;
use App\Services\Panel\Formats;
use App\Services\Panel\ProtectionRows;
use App\Services\Panel\ServerPresenter;
use App\Services\Panel\UnderTheHood;
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
    public function hardening(Request $request, Server $server, ServerPresenter $presenter, ProtectionRows $protections): Response|RedirectResponse
    {
        if ($server->trashed()) {
            return $this->destroyedServer($server);
        }

        $this->authorize('view', $server);

        return Inertia::render('servers/Hardening', [
            'server' => $presenter->header($server, $request->user()),
            'protections' => $protections->for($server),
            'ssh' => [
                'verified' => $server->sshHardened(),
                'owner' => $server->sshLeftToOwner(),
                'passwords_off' => $server->sshLeftToOwner() && $server->sshPasswordsOff(),
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

    public function underTheHood(Request $request, Server $server, UnderTheHood $machinery): Response|RedirectResponse
    {
        if ($server->trashed()) {
            return $this->destroyedServer($server);
        }

        $this->authorize('view', $server);

        return Inertia::render('servers/UnderTheHood', [
            'server' => ['id' => $server->id, 'name' => $server->name, 'href' => route('servers.show', $server)],
            ...$machinery->for($server, $request->user()),
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
