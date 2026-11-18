<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Address;

use App\Models\Application;
use App\Services\Network\AddressResolver;
use App\Services\Network\RemoteReachProbe;
use App\Services\Network\ServerAddresses;
use App\Services\SSH\SSHConnectionManager;

final class AppDomainReach
{
    public function __construct(
        private readonly SSHConnectionManager $connections,
        private readonly AddressResolver $resolver,
        private readonly ServerAddresses $addresses,
        private readonly DomainTwins $twins,
    ) {}

    /**
     * @return array<string, ReachReport>
     */
    public function check(Application $application): array
    {
        $server = $application->server;
        $twins = $this->twins->for($application);
        $domains = [...$application->allDomains(), ...array_keys($twins)];

        if ($server === null || $domains === []) {
            return [];
        }

        $connection = $this->connections->for($server);
        $reading = $this->addresses->read($connection);
        $this->addresses->keep($server, $reading);
        $reachability = new Reachability($this->resolver, $reading, $reading, new RemoteReachProbe($connection, $reading->direct));

        $reports = [];

        foreach ($domains as $domain) {
            $report = $reachability->check($domain);
            $reports[$domain] = isset($twins[$domain]) ? self::optional($report, $domain, $twins[$domain]) : $report;
        }

        return $reports;
    }

    private static function optional(ReachReport $report, string $twin, string $target): ReachReport
    {
        if ($report->ready === true) {
            return $report;
        }

        return new ReachReport($report->ready, [], ["{$twin} sends visitors to {$target} once it points at this server.", ...$report->problems, ...$report->notes], $report->records);
    }
}
