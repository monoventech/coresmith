<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Address;

use App\Models\Application;
use App\Models\Server;
use App\Models\ServerFrontDoor;
use App\Services\Network\AddressResolver;
use App\Services\Network\RemoteReachProbe;
use App\Services\Network\ServerAddresses;
use App\Services\SSH\SSHConnectionManager;
use App\Services\Tunnel\TunnelHosts;

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
        $reachability = new Reachability($this->resolver, $reading, $reading, new RemoteReachProbe($connection, $reading->direct), thisMachine: false);
        $tunnel = $server->tunnel()->first();
        $frontDoor = $server->frontDoor()->with('door')->first();

        $reports = [];

        foreach ($domains as $domain) {
            if ($tunnel?->carries($domain) === true) {
                $reports[$domain] = new ReachReport(true, [], ["{$domain} reaches this server through its Cloudflare tunnel."], []);

                continue;
            }

            $host = strtolower(trim($domain, '.'));
            $twin = $twins[$domain] ?? null;

            if ($frontDoor?->state === ServerFrontDoor::OPEN && TunnelHosts::routable($host)) {
                $report = self::throughDoor($frontDoor, $server, $host, $twin !== null);
                $reports[$domain] = $twin === null ? $report : self::optional($report, $domain, $twin, (string) $frontDoor->door?->name);

                continue;
            }

            $report = $reachability->check($domain);
            $reports[$domain] = $twin === null ? $report : self::optional($report, $domain, $twin);
        }

        return $reports;
    }

    private static function throughDoor(ServerFrontDoor $frontDoor, Server $server, string $domain, bool $optional): ReachReport
    {
        $door = (string) $frontDoor->door?->name;

        if ($frontDoor->carries($domain)) {
            return new ReachReport(true, [], ["{$domain} reaches this server through {$door}."], []);
        }

        $known = collect([...(array) $frontDoor->skipped, ...(array) $frontDoor->failing])
            ->first(fn(mixed $row): bool => is_array($row) && ($row['host'] ?? null) === $domain && is_string($row['reason'] ?? null));

        $records = array_map(
            fn(array $target): array => ['type' => $target['type'], 'name' => $domain, 'value' => $target['value'], 'note' => null],
            ServerAddresses::targetsFor($server),
        );

        return match (true) {
            $known !== null => new ReachReport(false, [$known['reason']], [], ($known['kind'] ?? 'dns') === 'dns' ? $records : []),
            $optional => new ReachReport(null, [], [], $records),
            default => new ReachReport(null, [], ["Coresmith is adding {$domain} to {$door}. Check again in a minute."], []),
        };
    }

    private static function optional(ReachReport $report, string $twin, string $target, string $where = 'this server'): ReachReport
    {
        if ($report->ready === true) {
            return $report;
        }

        return new ReachReport($report->ready, [], ["{$twin} sends visitors to {$target} once it points at {$where}.", ...$report->problems, ...$report->notes], $report->records);
    }
}
