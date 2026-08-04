<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\SshKey;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class SshKeyController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('settings/SshKeys', [
            'keys' => $request->user()->sshKeys()->latest()->get()->map(fn (SshKey $key): array => [
                'id' => $key->id,
                'name' => $key->name,
                'fingerprint' => $key->fingerprint,
                'created_at' => $key->created_at?->toIso8601String(),
            ]),
        ]);
    }

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

        return back()->with('success', 'Key added.');
    }

    public function destroy(SshKey $sshKey): RedirectResponse
    {
        $this->authorize('delete', $sshKey);

        $sshKey->delete();

        return back()->with('success', 'Key removed.');
    }

    private function fingerprint(string $publicKey): string
    {
        $parts = explode(' ', trim($publicKey));
        $blob = base64_decode($parts[1] ?? '', true);

        return 'SHA256:'.rtrim(base64_encode(hash('sha256', $blob === false ? '' : $blob, true)), '=');
    }
}
