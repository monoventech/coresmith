<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\TeamRole;
use App\Jobs\SyncAuthorizedKeys;
use App\Models\SshKey;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class SshKeyController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'public_key' => ['required', 'string', 'max:16384', 'regex:/^(ssh-ed25519|ssh-rsa|ecdsa-sha2-nistp[0-9]+) [A-Za-z0-9+\/=]+( .*)?$/'],
        ]);

        $request->user()->sshKeys()->create([
            'name' => $validated['name'],
            'public_key' => trim($validated['public_key']),
            'fingerprint' => $this->fingerprint($validated['public_key']),
        ]);

        $synced = $this->syncServersManagedBy($request->user());

        return back()->with('success', $synced === 0 ? 'Saved.' : 'Saved. The key can log in to your servers in a moment.');
    }

    public function destroy(SshKey $sshKey): RedirectResponse
    {
        $this->authorize('delete', $sshKey);

        $owner = $sshKey->user;

        $sshKey->delete();

        $this->syncServersManagedBy($owner);

        return back()->with('success', 'Removed.');
    }

    private function syncServersManagedBy(User $user): int
    {
        $teamIds = $user->teams()
            ->wherePivotIn('role', [TeamRole::Owner->value, TeamRole::Admin->value])
            ->pluck('teams.id');

        $servers = SyncAuthorizedKeys::activeServersOf($teamIds);

        SyncAuthorizedKeys::dispatchForServers($servers);

        return $servers->count();
    }

    private function fingerprint(string $publicKey): string
    {
        $parts = explode(' ', trim($publicKey));
        $blob = base64_decode($parts[1] ?? '', true);

        return 'SHA256:' . rtrim(base64_encode(hash('sha256', $blob === false ? '' : $blob, true)), '=');
    }
}
