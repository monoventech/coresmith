<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\DeploymentStatus;
use App\Enums\ServerStatus;
use App\Http\Requests\Server\StoreServerRequest;
use App\Http\Requests\Server\UpdateServerRequest;
use App\Jobs\ProvisionServer;
use App\Jobs\PruneReleases;
use App\Jobs\RebootServer;
use App\Jobs\TestSSHConnection;
use App\Models\Application;
use App\Models\Deployment;
use App\Models\Server;
use App\Services\Deployment\ReleasePaths;
use App\Services\Panel\ApplicationPresenter;
use App\Services\Panel\ServerPresenter;
use App\Services\SSH\KeyPairGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class ServerController extends Controller
{
    public const ENVIRONMENTS = ['Production', 'Staging', 'Development'];

    public function create(Request $request): Response
    {
        $this->authorize('create', Server::class);

        return Inertia::render('servers/Create', [
            'teams' => $request->user()->teams()->get(['teams.id', 'teams.name'])
                ->map(fn($team): array => ['id' => $team->id, 'name' => $team->name])->values(),
            'environments' => self::ENVIRONMENTS,
            'phases' => ServerPresenter::PROVISIONING_PHASES,
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

        activity()->performedOn($server)->log('server_added');

        return to_route('servers.show', $server)
            ->with('success', 'Server added. Add the public key to it, then test the connection.');
    }

    public function show(Request $request, Server $server, ServerPresenter $presenter): Response
    {
        $this->authorize('view', $server);

        $running = Deployment::query()
            ->whereIn('application_id', $server->applications()->select('id'))
            ->where('status', DeploymentStatus::Running->value)
            ->get()
            ->keyBy('application_id');

        return Inertia::render('servers/Show', [
            'server' => $presenter->header($server, $request->user()),
            'applications' => $server->applications()->orderBy('name')->get()->map(fn(Application $application): array => [
                'id' => $application->id,
                'name' => $application->name,
                'kind' => ApplicationPresenter::kind($application),
                'runtime' => ApplicationPresenter::runtime($application),
                'user' => $application->system_user,
                'ports' => ApplicationPresenter::portBlock($application),
                'state' => $running->has($application->id)
                    ? ['key' => 'deploying', 'label' => 'Deploying', 'tone' => 'accent']
                    : ApplicationPresenter::state($application),
                'href' => route('servers.applications.show', [$server, $application]),
            ])->values(),
        ]);
    }

    public function update(UpdateServerRequest $request, Server $server): RedirectResponse
    {
        $renamed = $request->has('name') && $request->string('name')->value() !== $server->name;

        $server->update($request->validated());

        if ($renamed) {
            activity()->performedOn($server)->log('server_renamed');
        }

        return back()->with('success', 'Saved.');
    }

    public function destroy(Server $server): RedirectResponse
    {
        $this->authorize('delete', $server);

        activity()->performedOn($server)->log('server_detached');

        $server->delete();

        return to_route('dashboard')->with('success', "Detached. {$server->name} was removed from the panel. The VM is untouched.");
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

        return back()->with('success', 'Provisioning started. Every phase is logged, close this page and it keeps going.');
    }

    public function reboot(Server $server): RedirectResponse
    {
        $this->authorize('update', $server);

        $busy = Deployment::query()
            ->whereIn('application_id', $server->applications()->select('id'))
            ->where('status', DeploymentStatus::Running->value)
            ->exists();

        if ($busy) {
            return back()->with('error', "A deploy is running on {$server->name}. Reboot once it finishes.");
        }

        RebootServer::dispatch($server);

        return back()->with('success', "Reboot scheduled. {$server->name} restarts in a few seconds, apps come back via systemd.");
    }

    public function prune(Server $server): RedirectResponse
    {
        $this->authorize('update', $server);

        PruneReleases::dispatch($server);

        return back()->with('success', 'Pruning old releases. Each app keeps its last ' . ReleasePaths::KEEP_RELEASES . ' and the live one, disk readings refresh within a minute.');
    }
}
