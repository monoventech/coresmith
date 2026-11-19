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
    private const BEST = "Best fix, with Cloudflare's proxy on";

    private const IF_YOURS = 'Only if the address is yours and you can forward ports 80 and 443';

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
            $notes = $this->notes($host, $v4, $v6, $v4Records, $v6Records, $direct, $rows);

            return new ReachReport(true, [], $proxied ? [...$notes, $this->proxied($host, $direct, $stable, $rows)] : $notes, $rows);
        }

        if ($problems !== [] || $proxied) {
            return new ReachReport($problems === [] ? null : false, $problems, $proxied ? [$this->proxied($host, $direct, $stable, $rows)] : [], $rows);
        }

        return new ReachReport(null, [], ["Coresmith couldn't ask get.coresmith.io whether {$host} can be reached from the internet, so it didn't check."]);
    }

    /**
     * @param  list<string>  $stable
     * @param  list<array{type: string, name: string, value: string, note: string|null}>  $rows
     */
    private function unpointed(string $host, ?DirectReach $direct, array $stable, array &$rows): string
    {
        if ($direct === null && $stable !== []) {
            $this->row($rows, 'AAAA', $host, $stable[0], self::BEST);

            return "{$host} doesn't point anywhere yet. Add an AAAA record pointing at {$stable[0]} and turn on Cloudflare's proxy for it, so visitors without IPv6 reach it too. Then check again.";
        }

        if ($direct === null) {
            return "{$host} doesn't point anywhere yet. Add a DNS record that points it at this server, then check again.";
        }

        if ($direct->http) {
            $this->row($rows, 'A', $host, $direct->address);

            return "{$host} doesn't point anywhere yet. Add an A record pointing at {$direct->address}, then check again.";
        }

        $lan = $this->lanAddress();
        $closed = "{$host} doesn't point anywhere yet, and this server's IPv4 address, {$direct->address}, doesn't answer on ports 80 and 443 from the internet.";

        if ($lan !== null && $stable !== []) {
            $this->row($rows, 'AAAA', $host, $stable[0], self::BEST);
            $this->row($rows, 'A', $host, $direct->address, self::IF_YOURS);

            return "{$closed} Add an AAAA record pointing at {$stable[0]} and turn on Cloudflare's proxy for {$host}, so visitors without IPv6 reach it too. Add the A record only if you can forward ports 80 and 443 to {$lan}. Then check again.";
        }

        $this->row($rows, 'A', $host, $direct->address);

        if ($stable !== []) {
            $this->row($rows, 'AAAA', $host, $stable[0]);
        }

        return match (true) {
            $lan !== null => "{$closed} Forward ports 80 and 443 on your router to {$lan}, then add an A record pointing at {$direct->address}. If your provider shares that address with other customers, use IPv6, or turn on IPv4 through Cloudflare on the server's page.",
            $stable !== [] => "{$closed} Open ports 80 and 443 in your provider's firewall and add an A record pointing at {$direct->address}, or add an AAAA record pointing at {$stable[0]} for visitors with IPv6. Then check again.",
            default => "{$closed} Open ports 80 and 443 in your provider's firewall, then add an A record pointing at {$direct->address}.",
        };
    }

    /**
     * @param  list<string>  $records
     * @param  list<string>  $stable
     * @param  list<string>  $problems
     * @param  list<array{type: string, name: string, value: string, note: string|null}>  $rows
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

        $problems[] = "{$host} points at {$named[0]}, which doesn't answer on ports 80 and 443 from the internet. Allow those ports to {$named[0]} in your router's IPv6 firewall, then check again.";

        return false;
    }

    /**
     * @param  list<string>  $records
     * @param  list<string>  $stable
     * @param  list<string>  $problems
     * @param  list<array{type: string, name: string, value: string, note: string|null}>  $rows
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
        $problem = "{$host} points at {$direct->address}, which doesn't answer on ports 80 and 443 from the internet.";

        if ($lan !== null && $stable !== []) {
            $this->row($rows, 'AAAA', $host, $stable[0], self::BEST);
        }

        $problems[] = match (true) {
            $lan === null => "{$problem} Open those ports in your provider's firewall, then check again.",
            $stable === [] => "{$problem} Forward those ports on your router to {$lan}, then check again. If your provider shares that address with other customers, use IPv6, or turn on IPv4 through Cloudflare on the server's page.",
            default => "{$problem} Add an AAAA record pointing at {$stable[0]}, remove the A record and turn on Cloudflare's proxy for {$host}, so visitors without IPv6 reach it too. Keep the A record only if you can forward ports 80 and 443 to {$lan}. Then check again.",
        };

        return false;
    }

    /**
     * @param  list<string>  $v4Records
     * @param  list<string>  $v6Records
     * @param  list<array{type: string, name: string, value: string, note: string|null}>  $rows
     * @return list<string>
     */
    private function notes(string $host, ?bool $v4, ?bool $v6, array $v4Records, array $v6Records, ?DirectReach $direct, array &$rows): array
    {
        $lan = $this->lanAddress();
        $notes = [];

        if ($v4 === true && $direct !== null && $lan !== null) {
            $notes[] = "If {$host} doesn't load inside your own network, add {$lan} {$host} to your local DNS or /etc/hosts.";
        }

        if ($v6 === true && $v4 === false && $direct !== null) {
            $waiting = "Visitors without IPv6 can't open {$host}: its A record points at {$direct->address}, which doesn't answer on ports 80 and 443.";
            $notes[] = $lan === null
                ? "{$waiting} Open those ports in your provider's firewall, or remove the A record and turn on Cloudflare's proxy for {$host}."
                : "{$waiting} Remove the A record and turn on Cloudflare's proxy for {$host}, or forward ports 80 and 443 to {$lan}.";
        }

        if ($v6 === true && $v4Records === [] && $v6Records !== []) {
            $notes[] = $this->ipv6Only($host, $v6Records[0], $direct, $lan, $rows);
        }

        return $notes;
    }

    /**
     * @param  list<array{type: string, name: string, value: string, note: string|null}>  $rows
     */
    private function ipv6Only(string $host, string $v6, ?DirectReach $direct, ?string $lan, array &$rows): string
    {
        $only = "{$host} has only an IPv6 address, so visitors without IPv6 can't open it.";

        if ($direct === null) {
            $this->row($rows, 'AAAA', $host, $v6, self::BEST);

            return "{$only} Turn on Cloudflare's proxy for {$host}, which answers them over IPv4.";
        }

        if ($direct->http) {
            $this->row($rows, 'A', $host, $direct->address);

            return "{$only} This server answers on IPv4 too, so add an A record pointing at {$direct->address}.";
        }

        if ($lan === null) {
            $this->row($rows, 'A', $host, $direct->address);

            return "{$only} Open ports 80 and 443 in your provider's firewall and add an A record pointing at {$direct->address}, or turn on Cloudflare's proxy for {$host}.";
        }

        $this->row($rows, 'AAAA', $host, $v6, self::BEST);
        $this->row($rows, 'A', $host, $direct->address, self::IF_YOURS);

        return "{$only} Turn on Cloudflare's proxy for {$host} to fix it. If you can forward ports 80 and 443 to {$lan}, an A record pointing at {$direct->address} works too.";
    }

    /**
     * @param  list<string>  $stable
     * @param  list<array{type: string, name: string, value: string, note: string|null}>  $rows
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
     * @param  list<array{type: string, name: string, value: string, note: string|null}>  $rows
     */
    private function row(array &$rows, string $type, string $host, string $value, ?string $note = null): void
    {
        foreach ($rows as $row) {
            if ($row['type'] === $type && $row['value'] === $value) {
                return;
            }
        }

        $rows[] = ['type' => $type, 'name' => $host, 'value' => $value, 'note' => $note];
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
