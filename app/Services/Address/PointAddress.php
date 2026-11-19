<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Address;

use App\Models\ServerTunnel;
use App\Services\Network\ReachProbe;
use App\Services\Network\StableAddresses;
use App\Services\Tunnel\CloudflareTunnels;
use App\Services\Tunnel\TunnelSync;
use RuntimeException;
use SensitiveParameter;

final class PointAddress
{
    public function __construct(
        private readonly CloudflareDns $dns,
        private readonly ReachProbe $probe,
        private readonly StableAddresses $stable,
        private readonly CloudflareTunnels $tunnels,
    ) {}

    public function point(string $host): ?string
    {
        $token = CloudflareConnection::token();

        if ($token === null) {
            return 'Connect Cloudflare first.';
        }

        try {
            $zone = $this->dns->zone($token, $host);

            if ($zone === null) {
                return $this->failed($host, "None of the zones that Cloudflare token can see holds {$host}.");
            }

            $tunnel = ServerTunnel::forThisMachine();

            if ($tunnel !== null) {
                return $this->throughTunnel($tunnel, $token, $zone, $host);
            }

            $direct = $this->probe->direct();
            $ipv4 = $direct !== null && ($direct->http || $direct->https) ? $direct->address : null;
            $ipv6 = $ipv4 === null ? ($this->stable->ipv6()[0] ?? null) : null;

            if ($ipv4 !== null) {
                $this->dns->point($token, $zone['id'], $host, 'A', $ipv4);
            } elseif ($ipv6 !== null) {
                $this->dns->point($token, $zone['id'], $host, 'AAAA', $ipv6);
            } else {
                return $this->failed($host, 'Coresmith found no address of this server that the internet reaches, so it changed no records.');
            }
        } catch (RuntimeException $refused) {
            return $this->failed($host, $refused->getMessage());
        }

        CloudflareConnection::synced($host, null);

        return null;
    }

    /**
     * @param  array{id: string, name: string, account: string}  $zone
     */
    private function throughTunnel(ServerTunnel $tunnel, #[SensitiveParameter] string $token, array $zone, string $host): ?string
    {
        $account = (string) $tunnel->account_id;
        $target = (string) $tunnel->target();

        if ($zone['account'] !== '' && $zone['account'] !== $account) {
            return $this->failed($host, "{$host} is in a different Cloudflare account from this machine's tunnel, so Coresmith can't route it through the tunnel.");
        }

        $hosts = array_values(array_unique([...array_filter((array) $tunnel->hosts, is_string(...)), strtolower($host)]));

        $this->tunnels->configure($token, $account, (string) $tunnel->tunnel_id, $hosts);

        $server = $tunnel->server;
        $reason = $this->dns->routeToTunnel($token, $zone['id'], $host, $target, $server === null ? [] : TunnelSync::ownAddresses($server));

        if ($reason !== null) {
            return $this->failed($host, $reason === '' ? "Coresmith couldn't route {$host} through the tunnel." : $reason);
        }

        $tunnel->forceFill(['hosts' => $hosts])->save();
        CloudflareConnection::synced($host, null);

        return null;
    }

    private function failed(string $host, string $error): string
    {
        CloudflareConnection::synced($host, $error);

        return $error;
    }
}
