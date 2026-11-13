<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Address;

use App\Models\Application;
use App\Services\Network\AddressResolver;
use App\Services\Network\RemoteReachProbe;
use App\Services\Network\ServerNetworkReading;
use App\Services\SSH\SSHConnectionManager;

final class AppDomainReach
{
    public function __construct(
        private readonly SSHConnectionManager $connections,
        private readonly AddressResolver $resolver,
    ) {}

    /**
     * @return array<string, ReachReport>
     */
    public function check(Application $application): array
    {
        $server = $application->server;
        $domains = $application->allDomains();

        if ($server === null || $domains === []) {
            return [];
        }

        $connection = $this->connections->for($server);
        $reading = ServerNetworkReading::parse($connection->execute(RemoteReachProbe::script(), timeout: RemoteReachProbe::SECONDS + 10)->output);
        $reachability = new Reachability($this->resolver, $reading, $reading, new RemoteReachProbe($connection, $reading->direct));

        $reports = [];

        foreach ($domains as $domain) {
            $reports[$domain] = $reachability->check($domain);
        }

        return $reports;
    }
}
