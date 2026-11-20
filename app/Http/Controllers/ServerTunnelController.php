<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\Edition;
use App\Jobs\SyncServerTunnel;
use App\Models\Server;
use App\Models\ServerTunnel;
use App\Services\Address\CloudflareConnection;
use App\Services\Address\CloudflareDns;
use App\Services\Tunnel\TunnelSync;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class ServerTunnelController extends Controller
{
    public function store(Request $request, Server $server, CloudflareDns $dns, TunnelSync $sync): RedirectResponse
    {
        $this->authorizeTunnel($request, $server);

        if ($server->frontDoor()->exists()) {
            return back()->with('error', 'IPv4 through another server is on. Turn it off first.');
        }

        $tunnel = ServerTunnel::query()->firstOrNew(['server_id' => $server->getKey()]);

        if (in_array($tunnel->state, [ServerTunnel::OPENING, ServerTunnel::CLOSING], true)) {
            return back()->with('error', 'Coresmith is still working on the tunnel. Try again when it finishes.');
        }

        $token = trim((string) $request->input('token'));

        if ($token !== '') {
            $refusal = $dns->refusal($token) ?? $sync->preflight($token, $server);

            if ($refusal !== null) {
                throw ValidationException::withMessages(['token' => $refusal]);
            }

            CloudflareConnection::connect($token);
        } elseif (CloudflareConnection::token() === null) {
            throw ValidationException::withMessages(['token' => 'Paste a Cloudflare API token to turn this on.']);
        } elseif ($tunnel->state !== ServerTunnel::OPEN && ($refusal = $sync->preflight((string) CloudflareConnection::token(), $server)) !== null) {
            return back()->with('error', $refusal);
        }

        $open = $tunnel->state === ServerTunnel::OPEN;
        $tunnel->forceFill(['state' => $open ? ServerTunnel::OPEN : ServerTunnel::OPENING, 'error' => null])->save();

        SyncServerTunnel::dispatch($server);

        return back()->with('success', $open ? 'Checking the tunnel again.' : 'Setting up the tunnel. This takes a minute or two.');
    }

    public function destroy(Request $request, Server $server): RedirectResponse
    {
        $this->authorizeTunnel($request, $server);

        $tunnel = $server->tunnel()->first();

        if ($tunnel === null) {
            return back();
        }

        if ($tunnel->state === ServerTunnel::OPENING) {
            return back()->with('error', 'Coresmith is still setting up the tunnel. Turn it off once it finishes.');
        }

        $tunnel->forceFill(['state' => ServerTunnel::CLOSING, 'error' => null])->save();

        SyncServerTunnel::dispatch($server);

        return back()->with('success', 'Turning off the tunnel.');
    }

    private function authorizeTunnel(Request $request, Server $server): void
    {
        $this->authorize('update', $server);

        abort_unless(Edition::current()->isSelfHosted() && $request->user()->managesInstance(), 403);
    }
}
