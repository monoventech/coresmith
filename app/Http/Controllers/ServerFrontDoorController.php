<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ServerStatus;
use App\Enums\TeamRole;
use App\Jobs\SyncServerFrontDoor;
use App\Models\Server;
use App\Models\ServerFrontDoor;
use App\Services\FrontDoor\FrontDoorAddresses;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

final class ServerFrontDoorController extends Controller
{
    public function store(Request $request, Server $server, FrontDoorAddresses $addresses): RedirectResponse
    {
        $this->authorizeFrontDoor($request, $server);

        if ($server->tunnel()->exists()) {
            return back()->with('error', 'IPv4 through Cloudflare is on. Turn it off first.');
        }

        $existing = $server->frontDoor()->with('door')->first();

        if ($existing !== null) {
            return $this->again($server, $existing);
        }

        $door = Server::query()->whereKey((string) $request->input('door'))->first();

        if (!self::eligible($server, $door)) {
            throw ValidationException::withMessages(['door' => 'Pick one of your servers whose IPv4 address the internet can reach.']);
        }

        $this->authorize('update', $door);

        Cache::lock('front-door-addresses:' . $door->getKey(), 10)->block(5, function () use ($server, $door, $addresses): void {
            $allocated = $addresses->allocate($door);

            ServerFrontDoor::query()->create([
                'server_id' => $server->getKey(),
                'door_server_id' => $door->getKey(),
                'state' => ServerFrontDoor::OPENING,
                'subnet' => $allocated['subnet'],
                'door_address' => $allocated['door'],
                'home_address' => $allocated['home'],
            ]);
        });

        SyncServerFrontDoor::dispatch($server);

        return back()->with('success', "Linking {$server->name} and {$door->name}. This takes a minute or two.");
    }

    public function destroy(Request $request, Server $server): RedirectResponse
    {
        $this->authorizeFrontDoor($request, $server);

        $frontDoor = $server->frontDoor()->with('door')->first();

        if ($frontDoor === null) {
            return back();
        }

        if ($frontDoor->state === ServerFrontDoor::OPENING) {
            return back()->with('error', 'Coresmith is still linking the servers. Turn it off once it finishes.');
        }

        $frontDoor->forceFill(['state' => ServerFrontDoor::CLOSING, 'error' => null])->save();

        SyncServerFrontDoor::dispatch($server);

        return back()->with('success', "Turning off IPv4 through {$frontDoor->door?->name}.");
    }

    public static function eligible(Server $home, ?Server $door): bool
    {
        return $door !== null
            && !$door->is($home)
            && $door->team_id === $home->team_id
            && $door->status === ServerStatus::Active
            && (((array) $door->public_addresses)['ipv4_open'] ?? null) === true;
    }

    private function again(Server $server, ServerFrontDoor $frontDoor): RedirectResponse
    {
        if (in_array($frontDoor->state, [ServerFrontDoor::OPENING, ServerFrontDoor::CLOSING], true)) {
            return back()->with('error', 'Coresmith is still working on this. Try again when it finishes.');
        }

        $open = $frontDoor->state === ServerFrontDoor::OPEN;
        $frontDoor->forceFill(['state' => $open ? ServerFrontDoor::OPEN : ServerFrontDoor::OPENING, 'error' => null])->save();

        SyncServerFrontDoor::dispatch($server);

        return back()->with('success', $open ? 'Checking again.' : "Linking {$server->name} and {$frontDoor->door?->name} again.");
    }

    private function authorizeFrontDoor(Request $request, Server $server): void
    {
        $this->authorize('update', $server);

        abort_unless($request->user()->roleIn($server->team) === TeamRole::Owner, 403);
    }
}
