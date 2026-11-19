<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Tunnel;

use App\Models\Server;
use App\Services\Address\CertificateSource;
use App\Services\Address\DomainTwins;
use App\Services\Address\InstallationAddress;
use App\Services\SafeName;

final readonly class TunnelHosts
{
    public function __construct(private InstallationAddress $address, private DomainTwins $twins) {}

    /**
     * @return array<string, bool>
     */
    public function for(Server $server): array
    {
        $hosts = [];

        if ($server->runs_coresmith && self::routable($panel = strtolower($this->address->host()))) {
            $hosts[$panel] = true;
        }

        foreach ($server->applications()->get() as $application) {
            foreach ($application->allDomains() as $domain) {
                $domain = strtolower(trim($domain, '.'));

                if (self::routable($domain)) {
                    $hosts[$domain] = true;
                }
            }

            foreach (array_keys($this->twins->for($application)) as $twin) {
                if (self::routable($twin)) {
                    $hosts[$twin] ??= false;
                }
            }
        }

        return $hosts;
    }

    public static function routable(string $host): bool
    {
        return !str_starts_with($host, '*.') && SafeName::isDomain($host) && InstallationAddress::certificate($host) === CertificateSource::Public;
    }
}
