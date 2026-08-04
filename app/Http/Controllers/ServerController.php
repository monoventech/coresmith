<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ServerStatus;
use App\Http\Requests\Server\StoreServerRequest;
use App\Http\Requests\Server\UpdateServerRequest;
use App\Jobs\ProvisionServer;
use App\Jobs\TestSSHConnection;
use App\Models\Server;
use App\Services\SSH\KeyPairGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class ServerController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Server::class);

        return Inertia::render('servers/Index', [
            'servers' => Server::query()
                ->whereIn('team_id', $request->user()->teams()->pluck('teams.id'))
                ->with('team')
                ->latest()
                ->get()
                ->map(fn (Server $server): array => $this->summarise($server)),
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', Server::class);

        return Inertia::render('servers/Create', [
            'teams' => $request->user()->teams()->get(['teams.id', 'teams.name']),
        ]);
    }

    public function store(StoreServerRequest $request, KeyPairGenerator $keys): RedirectResponse
    {
        $pair = $keys->generate();

        $server = Server::create([
            ...$request->safe()->except([]),
            'ssh_private_key' => $pair->privateKey,
            'ssh_public_key' => $pair->publicKey,
            'status' => ServerStatus::Pending,
        ]);

        return to_route('servers.show', $server)
            ->with('success', 'Server added. Add the public key to the server, then test the connection.');
    }

    public function show(Server $server): Response
    {
        $this->authorize('view', $server);

        return Inertia::render('servers/Show', [
            'server' => [
                ...$this->summarise($server),
                'ssh_public_key' => $server->ssh_public_key,
                'host_key_fingerprint' => $server->host_key_fingerprint,
                'provisioning_log' => $server->provisioning_log,
                'installed_runtimes' => $server->installed_runtimes,
                'meta' => $server->meta,
            ],
            'can' => [
                'update' => $this->can('update', $server),
                'delete' => $this->can('delete', $server),
                'manageFirewall' => $this->can('manageFirewall', $server),
            ],
        ]);
    }

    public function edit(Server $server): Response
    {
        $this->authorize('update', $server);

        return Inertia::render('servers/Edit', ['server' => $this->summarise($server)]);
    }

    public function update(UpdateServerRequest $request, Server $server): RedirectResponse
    {
        $server->update($request->validated());

        return to_route('servers.show', $server)->with('success', 'Server updated.');
    }

    public function destroy(Server $server): RedirectResponse
    {
        $this->authorize('delete', $server);

        $server->delete();

        return to_route('servers.index')->with('success', 'Server removed.');
    }

    public function testConnection(Server $server): RedirectResponse
    {
        $this->authorize('update', $server);

        TestSSHConnection::dispatch($server);

        return back()->with('success', 'Testing the connection. This page updates when it finishes.');
    }

    public function provision(Server $server): RedirectResponse
    {
        $this->authorize('update', $server);

        ProvisionServer::dispatch($server);

        return back()->with('success', 'Provisioning started.');
    }

    /**
     * @return array<string, mixed>
     */
    private function summarise(Server $server): array
    {
        return [
            'id' => $server->id,
            'name' => $server->name,
            'ip_address' => $server->ip_address,
            'ssh_port' => $server->ssh_port,
            'ssh_user' => $server->ssh_user,
            'status' => $server->status?->value,
            'os_name' => $server->os_name,
            'os_version' => $server->os_version,
            'provisioning_phase' => $server->provisioning_phase,
            'audit_logging_enabled' => (bool) $server->audit_logging_enabled,
            'last_connected_at' => $server->last_connected_at?->toIso8601String(),
            'team' => ['id' => $server->team_id, 'name' => $server->team?->name],
        ];
    }

    private function can(string $ability, Server $server): bool
    {
        return request()->user()?->can($ability, $server) ?? false;
    }
}
