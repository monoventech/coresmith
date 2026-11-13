<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Address;

use App\Services\Network\AddressResolver;
use App\Services\Network\CloudflareRanges;
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
        $rows = [];

        if ($v4Records === [] && $v6Records === []) {
            return new ReachReport(false, [$this->unpointed($host, $direct, $stable, $rows)], [], $rows);
        }

        $v4Proxied = CloudflareRanges::containsAll($v4Records);
        $v6Proxied = CloudflareRanges::containsAll($v6Records);
        $proxied = $v4Proxied || $v6Proxied;
        $problems = [];
        $v6 = $v6Records === [] || $v6Proxied ? null : $this->ipv6($host, $v6Records, $stable, $problems, $rows);
        $v4 = $v4Records === [] || $v4Proxied ? null : $this->ipv4($host, $v4Records, $direct, $stable, $problems, $rows);

        if ($v4 === true || $v6 === true) {
            $rows = [];
            $notes = $this->notes($host, $v4, $v6, $v4Records, $direct, $rows);

            return new ReachReport(true, [], $proxied ? [...$notes, $this->proxied($host, $direct, $stable, $rows)] : $notes, $rows);
        }

        if ($problems !== [] || $proxied) {
            return new ReachReport($problems === [] ? null : false, $problems, $proxied ? [$this->proxied($host, $direct, $stable, $rows)] : [], $rows);
        }

        return new ReachReport(null, [], ["Coresmith couldn't ask get.coresmith.io whether {$host} can be reached from the internet, so it didn't check."]);
    }

    /**
     * @param  list<string>  $stable
     * @param  list<array{type: string, name: string, value: string}>  $rows
     */
    private function unpointed(string $host, ?DirectReach $direct, array $stable, array &$rows): string
    {
        if ($direct === null) {
            return "{$host} doesn't point anywhere yet. Add a DNS record that points it at this server, then check again.";
        }

        $this->row($rows, 'A', $host, $direct->address);

        if ($direct->http) {
            return "{$host} doesn't point anywhere yet. Add an A record for {$host} pointing at {$direct->address}, then check again.";
        }

        if ($stable !== []) {
            $this->row($rows, 'AAAA', $host, $stable[0]);
        }

        $lan = $this->lanAddress();
        $closed = "{$host} doesn't point anywhere yet, and nothing on the internet reaches this server's IPv4 address, {$direct->address}, on ports 80 and 443.";

        return match (true) {
            $lan !== null && $stable !== [] => "{$closed} Either your router isn't forwarding those ports to {$lan}, or your internet provider shares that address with other customers, as many home connections do. If you can forward them, add an A record for {$host} pointing at {$direct->address}. If you can't, add an AAAA record for {$host} pointing at {$stable[0]}. That reaches visitors with IPv6; to reach everyone else as well, put {$host} behind a proxy that answers on IPv4, such as Cloudflare's. Then check again.",
            $lan !== null => "{$closed} Forward TCP ports 80 and 443 on your router to {$lan}, then add an A record for {$host} pointing at {$direct->address}. If your internet provider shares that address with other customers, this server needs an IPv6 address before anyone can reach it.",
            $stable !== [] => "{$closed} Open ports 80 and 443 in your provider's firewall and add an A record for {$host} pointing at {$direct->address}, or add an AAAA record for {$host} pointing at {$stable[0]}, which only reaches visitors with IPv6. Then check again.",
            default => "{$closed} Open ports 80 and 443 in your provider's firewall, then add an A record for {$host} pointing at {$direct->address}.",
        };
    }

    /**
     * @param  list<string>  $records
     * @param  list<string>  $stable
     * @param  list<string>  $problems
     * @param  list<array{type: string, name: string, value: string}>  $rows
     */
    private function ipv6(string $host, array $records, array $stable, array &$problems, array &$rows): ?bool
    {
        $named = array_values(array_filter($stable, fn(string $address): bool => $this->listed($address, $records)));

        if ($named === []) {
            if ($stable !== []) {
                $problems[] = "{$host} points at {$records[0]}, but this server's IPv6 address is {$stable[0]}. Point it at {$stable[0]}, then check again.";
                $this->row($rows, 'AAAA', $host, $stable[0]);

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

        $problems[] = "{$host} points at {$named[0]}, but nothing reaches it on ports 80 and 443 from the internet. Home routers often block incoming IPv6, so allow ports 80 and 443 to {$named[0]} in your router's IPv6 firewall, then check again.";

        return false;
    }

    /**
     * @param  list<string>  $records
     * @param  list<string>  $stable
     * @param  list<string>  $problems
     * @param  list<array{type: string, name: string, value: string}>  $rows
     */
    private function ipv4(string $host, array $records, ?DirectReach $direct, array $stable, array &$problems, array &$rows): ?bool
    {
        if ($direct === null) {
            return null;
        }

        if (!in_array($direct->address, $records, true)) {
            $problems[] = "{$host} points at {$records[0]}, but this server's public address is {$direct->address}. Point it at {$direct->address}, then check again.";
            $this->row($rows, 'A', $host, $direct->address);

            return false;
        }

        if ($direct->http) {
            return true;
        }

        $lan = $this->lanAddress();
        $problem = "{$host} points at {$direct->address}, but nothing reaches this server on ports 80 and 443 from the internet.";

        if ($lan !== null && $stable !== []) {
            $this->row($rows, 'AAAA', $host, $stable[0]);
        }

        $problems[] = match (true) {
            $lan === null => "{$problem} Open ports 80 and 443 in your provider's firewall, then check again.",
            $stable === [] => "{$problem} Forward TCP ports 80 and 443 on your router to {$lan}, then check again. If your internet provider shares that address with other customers, this server needs an IPv6 address before anyone can reach it.",
            default => "{$problem} Forward TCP ports 80 and 443 on your router to {$lan}, then check again. If you can't, or your internet provider shares that address with other customers, use IPv6: add an AAAA record for {$host} pointing at {$stable[0]}, remove the A record, and allow ports 80 and 443 to it in your router's IPv6 firewall. To reach visitors without IPv6 too, put {$host} behind a proxy that answers on IPv4, such as Cloudflare's.",
        };

        return false;
    }

    /**
     * @param  list<string>  $v4Records
     * @param  list<array{type: string, name: string, value: string}>  $rows
     * @return list<string>
     */
    private function notes(string $host, ?bool $v4, ?bool $v6, array $v4Records, ?DirectReach $direct, array &$rows): array
    {
        $lan = $this->lanAddress();
        $notes = [];

        if ($v4 === true && $direct !== null && $lan !== null) {
            $notes[] = "Inside your own network, your router may not loop back to {$direct->address}. If {$host} doesn't load there, add {$lan} {$host} to your local DNS or /etc/hosts.";
        }

        if ($v6 === true && $v4 === false && $direct !== null) {
            $notes[] = "The A record for {$host} points at {$direct->address}, which the internet can't reach on ports 80 and 443. Remove it so visitors without IPv6 aren't left waiting. To reach them, put {$host} behind a proxy that answers on IPv4, such as Cloudflare's.";
        }

        if ($v6 === true && $v4Records === []) {
            $notes[] = $this->ipv6Only($host, $direct, $rows);
        }

        return $notes;
    }

    /**
     * @param  list<array{type: string, name: string, value: string}>  $rows
     */
    private function ipv6Only(string $host, ?DirectReach $direct, array &$rows): string
    {
        $only = "{$host} has only an IPv6 address, so visitors on networks without IPv6 can't open it.";

        if ($direct === null) {
            return "{$only} To reach them, add an A record for this server's IPv4 address if the internet reaches it on ports 80 and 443, or put {$host} behind a proxy that answers on IPv4, such as Cloudflare's.";
        }

        $this->row($rows, 'A', $host, $direct->address);

        return $direct->http
            ? "{$only} The internet reaches this server's IPv4 address too, so add an A record for {$host} pointing at {$direct->address}."
            : "{$only} Nothing on the internet reaches this server's IPv4 address, {$direct->address}, on ports 80 and 443, so if you can open or forward those ports, add an A record for {$host} pointing at {$direct->address}. If your internet provider shares that address with other customers, put {$host} behind a proxy that answers on IPv4, such as Cloudflare's.";
    }

    /**
     * @param  list<string>  $stable
     * @param  list<array{type: string, name: string, value: string}>  $rows
     */
    private function proxied(string $host, ?DirectReach $direct, array $stable, array &$rows): string
    {
        if ($stable !== []) {
            $this->row($rows, 'AAAA', $host, $stable[0]);
        }

        if ($direct?->http === true) {
            $this->row($rows, 'A', $host, $direct->address);
        }

        return "{$host} goes through Cloudflare's proxy, so Coresmith can't test it from outside. In Cloudflare, point {$host} at this server and set SSL/TLS to Full (strict).";
    }

    /**
     * @param  list<array{type: string, name: string, value: string}>  $rows
     */
    private function row(array &$rows, string $type, string $host, string $value): void
    {
        $row = ['type' => $type, 'name' => $host, 'value' => $value];

        if (!in_array($row, $rows, true)) {
            $rows[] = $row;
        }
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
