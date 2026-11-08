<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Jobs\ChangeAddress;
use App\Services\Address\AddressChange;
use App\Services\Address\InstallationAddress;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class AddressController extends Controller
{
    public function update(Request $request, InstallationAddress $address): RedirectResponse
    {
        abort_unless($request->user()->managesInstance(), 403);

        $host = InstallationAddress::normalise((string) $request->input('address'));
        $refusal = match (true) {
            AddressChange::running() => 'Coresmith is already changing its address. Wait for it to finish.',
            default => $address->refusal($host),
        };

        if ($refusal === null && !$address->managed()) {
            $refusal = "This install can't change its address from here yet. Run the installer again with --domain {$host}.";
        }

        if ($refusal !== null) {
            throw ValidationException::withMessages(['address' => $refusal]);
        }

        $from = $address->host();

        AddressChange::begin($from, $host);
        ChangeAddress::dispatch($host);

        return back()->with('success', "Switching to {$host}. Coresmith keeps answering on {$from} until the certificate is ready.");
    }
}
