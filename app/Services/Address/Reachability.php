<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Address;

use App\Services\Network\AddressResolver;
use App\Services\Network\DirectReach;
use App\Services\Network\LocalAddresses;
use App\Services\Network\ReachProbe;
use App\Services\Network\StableAddresses;

final class Reachability
{
    public function __construct(
        private readonly AddressResolver $resolver,
        private readonly LocalAddresses $local,
        private readonly StableAddresses $stable,
        private readonly ReachProbe $probe,
    ) {}

    public function check(string $host): ReachReport
    {
        $records = $this->resolver->addresses($host);
        $v4Records = array_values(array_filter($records, fn(string $record): bool => filter_var($record, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false));
        $v6Records = array_values(array_filter($records, fn(string $record): bool => filter_var($record, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false));
        $stable = $this->stable->ipv6();
        $direct = $this->probe->direct();

        if ($v4Records === [] && $v6Records === []) {
            return new ReachReport(false, [$this->unpointed($host, $direct, $stable)]);
        }

        $problems = [];
        $v6 = $v6Records === [] ? null : $this->ipv6($host, $v6Records, $stable, $problems);
        $v4 = $v4Records === [] ? null : $this->ipv4($host, $v4Records, $direct, $stable, $problems);

        if ($v4 === true || $v6 === true) {
            return new ReachReport(true, [], $this->notes($host, $v4, $v6, $direct));
        }

        if ($problems !== []) {
            return new ReachReport(false, $problems);
        }

        return new ReachReport(null, [], ["Coresmith couldn't ask get.coresmith.io whether {$host} can be reached from the internet, so it didn't check."]);
    }

    /**
     * @param  list<string>  $stable
     */
    private function unpointed(string $host, ?DirectReach $direct, array $stable): string
    {
        return match (true) {
            $direct !== null && $direct->http => "{$host} doesn't point anywhere yet. Add an A record for {$host} pointing at {$direct->address}, then check again.",
            $direct !== null && $stable !== [] => "{$host} doesn't point anywhere yet, and nothing reaches this server's IPv4 address ({$direct->address}) on ports 80 and 443 from the internet. Add an AAAA record for {$host} pointing at {$stable[0]}, then check again.",
            default => "{$host} doesn't point anywhere yet. Add a DNS record that points it at this server, then check again.",
        };
    }

    /**
     * @param  list<string>  $records
     * @param  list<string>  $stable
     * @param  list<string>  $problems
     */
    private function ipv6(string $host, array $records, array $stable, array &$problems): ?bool
    {
        $named = array_values(array_filter($stable, fn(string $address): bool => $this->listed($address, $records)));

        if ($named === []) {
            if ($stable !== []) {
                $problems[] = "{$host} points at {$records[0]}, but this server's IPv6 address is {$stable[0]}. Point it at {$stable[0]}, then check again.";

                return false;
            }

            return null;
        }

        $reach = $this->probe->host($host, $named[0]);

        if ($reach === null || !$reach->points) {
            return null;
        }

        if ($reach->http) {
            return true;
        }

        $problems[] = "{$host} points at {$named[0]}, but nothing reaches it on ports 80 and 443 from the internet. Allow ports 80 and 443 to {$named[0]} in your router's IPv6 firewall, then check again.";

        return false;
    }

    /**
     * @param  list<string>  $records
     * @param  list<string>  $stable
     * @param  list<string>  $problems
     */
    private function ipv4(string $host, array $records, ?DirectReach $direct, array $stable, array &$problems): ?bool
    {
        if ($direct === null) {
            return null;
        }

        if (!in_array($direct->address, $records, true)) {
            $problems[] = "{$host} points at {$records[0]}, but this server's public address is {$direct->address}. Point it at {$direct->address}, then check again.";

            return false;
        }

        if ($direct->http) {
            return true;
        }

        $lan = $this->lanAddress();
        $problem = "{$host} points at {$direct->address}, but nothing reaches this server on ports 80 and 443 from the internet.";

        $problems[] = match (true) {
            $lan === null => "{$problem} Open ports 80 and 443 in your provider's firewall, then check again.",
            $stable === [] => "{$problem} Forward TCP ports 80 and 443 on your router to {$lan}, then check again.",
            default => "{$problem} Forward TCP ports 80 and 443 on your router to {$lan}, then check again. If you can't, or your provider shares that address with other customers, use IPv6: add an AAAA record for {$host} pointing at {$stable[0]}, remove the A record, and allow ports 80 and 443 to it in your router's IPv6 firewall.",
        };

        return false;
    }

    /**
     * @return list<string>
     */
    private function notes(string $host, ?bool $v4, ?bool $v6, ?DirectReach $direct): array
    {
        $lan = $this->lanAddress();

        return array_values(array_filter([
            $v4 === true && $direct !== null && $lan !== null
                ? "Inside your own network, your router may not loop back to {$direct->address}. If {$host} doesn't load there, add {$lan} {$host} to your local DNS or /etc/hosts."
                : null,
            $v6 === true && $v4 === false && $direct !== null
                ? "The A record for {$host} points at {$direct->address}, which the internet can't reach on ports 80 and 443. Remove it so visitors without IPv6 aren't left waiting."
                : null,
        ]));
    }

    private function lanAddress(): ?string
    {
        foreach ($this->local->all() as $address) {
            $v4 = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;

            if ($v4 && !str_starts_with($address, '127.') && filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE) === false) {
                return $address;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $records
     */
    private function listed(string $address, array $records): bool
    {
        $packed = @inet_pton($address);

        foreach ($records as $record) {
            if ($packed !== false && @inet_pton($record) === $packed) {
                return true;
            }
        }

        return false;
    }
}
