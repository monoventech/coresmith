<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Address;

final class AddressGate
{
    public function __construct(
        private readonly InstallationAddress $address,
        private readonly Reachability $reachability,
    ) {}

    public function check(string $host): AddressVerdict
    {
        if (AddressChange::running()) {
            return new AddressVerdict('Coresmith is already changing its address. Wait for it to finish.');
        }

        $refusal = $this->address->formRefusal($host);

        if ($refusal !== null) {
            return new AddressVerdict($refusal);
        }

        $verdict = $this->reach($host);

        if ($verdict->refusal === null && !$this->address->managed()) {
            return new AddressVerdict("This install can't change its address from here yet. Run the installer again with --domain {$host}.");
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

        $report = $this->reachability->check($host);

        return match ($report->ready) {
            false => new AddressVerdict(implode(' ', $report->problems), [], $report->problems),
            true => new AddressVerdict(null, $report->notes),
            null => new AddressVerdict($this->address->dnsRefusal($host), $report->notes),
        };
    }
}
