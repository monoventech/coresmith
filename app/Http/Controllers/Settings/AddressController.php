<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\Address\AddressChange;
use App\Services\Address\AddressCheck;
use App\Services\Address\AddressGate;
use App\Services\Address\AddressRequest;
use App\Services\Address\CloudflareConnection;
use App\Services\Address\CloudflareDns;
use App\Services\Address\InstallationAddress;
use App\Services\Address\PointAddress;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class AddressController extends Controller
{
    public function update(Request $request, InstallationAddress $address, AddressGate $gate): RedirectResponse
    {
        abort_unless($request->user()->managesInstance(), 403);

        $host = InstallationAddress::normalise((string) $request->input('address'));
        $refusal = $gate->check($host)->refusal;

        if ($refusal !== null) {
            throw ValidationException::withMessages(['address' => $refusal]);
        }

        $from = $address->host();

        AddressChange::begin($from, $host);

        try {
            AddressRequest::submit($host);
        } catch (RuntimeException $unasked) {
            AddressChange::fail($unasked->getMessage());

            throw ValidationException::withMessages(['address' => $unasked->getMessage()]);
        }

        return back()->with('success', "Switching to {$host}. Coresmith keeps answering on {$from} until the certificate is ready.");
    }

    public function connectCloudflare(Request $request, CloudflareDns $dns): RedirectResponse
    {
        abort_unless($request->user()->managesInstance(), 403);

        $token = trim((string) $request->input('token'));
        $refusal = $token === '' ? 'Paste the API token Cloudflare showed you once.' : $dns->refusal($token);

        if ($refusal !== null) {
            throw ValidationException::withMessages(['token' => $refusal]);
        }

        CloudflareConnection::connect($token);

        return back()->with('success', 'Cloudflare is connected. Coresmith keeps its address pointed at this server.');
    }

    public function disconnectCloudflare(Request $request): RedirectResponse
    {
        abort_unless($request->user()->managesInstance(), 403);

        CloudflareConnection::disconnect();

        return back()->with('success', 'Cloudflare is disconnected. Coresmith no longer changes its DNS records.');
    }

    public function point(Request $request, PointAddress $point): RedirectResponse
    {
        abort_unless($request->user()->managesInstance(), 403);

        $host = InstallationAddress::normalise((string) $request->input('address'));
        $error = $point->point($host);

        return $error === null
            ? back()->with('success', "Cloudflare now points {$host} at this server. Check again in a minute.")
            : back()->with('error', $error);
    }

    public function check(Request $request, AddressGate $gate): RedirectResponse
    {
        abort_unless($request->user()->managesInstance(), 403);

        $host = InstallationAddress::normalise((string) $request->input('address'));
        AddressCheck::record($host, $gate->check($host));

        return back();
    }
}
