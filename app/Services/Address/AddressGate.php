<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Address;

use App\Models\ServerFrontDoor;
use App\Models\ServerTunnel;

final class AddressGate
{
    public function __construct(
        private readonly InstallationAddress $address,
        private readonly Reachability $reachability,
    ) {}

    public function check(string $host): AddressVerdict
    {
        if (AddressChange::running()) {
            return new AddressVerdict('Coresmith is already changing its address. Wait for it to finish.', pointable: false);
        }

        $refusal = $this->address->formRefusal($host);

        if ($refusal !== null) {
            return new AddressVerdict($refusal, pointable: false);
        }

        $verdict = $this->reach($host);

        if ($verdict->refusal === null && !$this->address->managed()) {
            return new AddressVerdict("This install can't change its address from here yet. Run the installer again with --domain {$host}.", pointable: false);
        }

        return $verdict;
    }

    private function reach(string $host): AddressVerdict
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return new AddressVerdict(null);
        }

        if (InstallationAddress::certificate($host) !== CertificateSource::Public) {
            return new AddressVerdict($this->address->dnsRefusal($host));
        }

        $tunnel = ServerTunnel::forThisMachine();

        if ($tunnel?->carries($host) === true) {
            return new AddressVerdict(null, ["{$host} reaches Coresmith through this machine's Cloudflare tunnel."]);
        }

        if ($tunnel !== null) {
            return new AddressVerdict("{$host} isn't on this machine's Cloudflare tunnel yet. Point it here with Cloudflare, then check again.");
        }

        $frontDoor = ServerFrontDoor::forThisMachine();

        if ($frontDoor?->carries($host) === true) {
            return new AddressVerdict(null, ["{$host} reaches Coresmith through {$frontDoor->door?->name}."]);
        }

        if ($frontDoor !== null) {
            return new AddressVerdict("Coresmith can't change its address while visitors come in through {$frontDoor->door?->name}. Turn off IPv4 through another server on this machine's page, change the address, then turn it on again.", pointable: false);
        }

        $report = $this->reachability->check($host);

        return match ($report->ready) {
            false => new AddressVerdict(implode(' ', $report->problems), [], $report->problems, $report->records),
            true => new AddressVerdict(null, $report->notes, [], $report->records),
            null => new AddressVerdict($this->address->dnsRefusal($host), $report->notes, [], $report->records),
        };
    }
}
