<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\Address\AddressChange;
use App\Services\Address\AddressRequest;
use App\Services\Address\CertificateSource;
use App\Services\Address\InstallationAddress;
use App\Services\Address\Reachability;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class AddressController extends Controller
{
    public function update(Request $request, InstallationAddress $address, Reachability $reachability): RedirectResponse
    {
        abort_unless($request->user()->managesInstance(), 403);

        $host = InstallationAddress::normalise((string) $request->input('address'));
        $refusal = AddressChange::running()
            ? 'Coresmith is already changing its address. Wait for it to finish.'
            : $address->formRefusal($host) ?? $this->reachRefusal($address, $reachability, $host) ?? ($address->managed() ? null : "This install can't change its address from here yet. Run the installer again with --domain {$host}.");

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

    private function reachRefusal(InstallationAddress $address, Reachability $reachability, string $host): ?string
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return null;
        }

        if (InstallationAddress::certificate($host) !== CertificateSource::Public) {
            return $address->dnsRefusal($host);
        }

        $report = $reachability->check($host);

        return match ($report->ready) {
            false => implode(' ', $report->problems),
            true => null,
            null => $address->dnsRefusal($host),
        };
    }
}
