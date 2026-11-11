<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ServerStatus;
use App\Http\Requests\Server\StoreThisMachineRequest;
use App\Jobs\ConnectThisMachine;
use App\Models\Server;
use App\Models\Team;
use App\Services\Billing\PlanGate;
use App\Services\Server\ThisMachine;
use App\Services\SSH\KeyPairGenerator;
use App\Services\Teams\TeamAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

final class ThisMachineController extends Controller
{
    public function store(StoreThisMachineRequest $request, ThisMachine $machine, KeyPairGenerator $keys, PlanGate $plans, TeamAccess $access): RedirectResponse
    {
        if (!$machine->installed()) {
            return back()->with('error', "This install's installer is from before Coresmith could use its own machine. Run it again with " . ThisMachine::INSTALLER . ', then try again.');
        }

        $team = Team::query()->findOrFail($request->validated('team_id'));

        $server = Cache::lock('this-machine:adding', 30)->block(10, function () use ($request, $machine, $keys, $plans, $team): Server|string {
            $taken = $machine->server();

            if ($taken !== null) {
                return (string) $taken->team?->name;
            }

            return $plans->creatingServer($team, $request->user(), function () use ($request, $keys, $team): Server {
                $pair = $keys->generate('coresmith@' . (Str::slug((string) $request->validated('name')) ?: 'this-machine'));

                return Server::create([
                    'team_id' => $team->getKey(),
                    'name' => $request->validated('name'),
                    'environment' => $request->validated('environment'),
                    'ip_address' => '127.0.0.1',
                    'ssh_port' => 22,
                    'ssh_user' => 'root',
                    'runs_coresmith' => true,
                    'ssh_private_key' => $pair->privateKey,
                    'ssh_public_key' => $pair->publicKey,
                    'status' => ServerStatus::Pending,
                ]);
            });
        });

        if (is_string($server)) {
            return back()->with('error', "This machine is already a server in {$server}.");
        }

        activity()->performedOn($server)->log('server_added');
        $access->grant($team, $request->user(), $server);

        ConnectThisMachine::dispatch($server);

        return to_route('servers.show', $server)->with('success', 'Coresmith is getting access to this machine. It checks the machine next.');
    }

    public function retry(Server $server): RedirectResponse
    {
        $this->authorize('update', $server);

        abort_unless($server->runs_coresmith && $server->status === ServerStatus::Pending && $server->last_connected_at === null, 404);

        $server->forceFill(['last_connection_error' => null])->save();

        ConnectThisMachine::dispatch($server);

        return back()->with('success', 'Asking for access to this machine again.');
    }
}
