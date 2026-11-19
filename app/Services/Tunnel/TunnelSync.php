<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Tunnel;

use App\Exceptions\SSHCommandFailedException;
use App\Exceptions\UnsupportedOperatingSystemException;
use App\Models\Server;
use App\Models\ServerTunnel;
use App\Services\Address\CloudflareConnection;
use App\Services\Address\CloudflareDns;
use App\Services\Caddy\CaddyApiClient;
use App\Services\Network\AddressResolver;
use App\Services\Network\ServerAddresses;
use App\Services\SSH\SSHConnection;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use RuntimeException;
use SensitiveParameter;
use Throwable;

final class TunnelSync
{
    public const CONNECT_SECONDS = 60;

    public const POLL_SECONDS = 3;

    public const NO_TOKEN = 'Connect Cloudflare first, then turn this on again.';

    public const NO_ACCOUNT = "Coresmith's Cloudflare token can't see an account to put the tunnel in. In Cloudflare, edit the token, add Account, Cloudflare Tunnel, Edit, then try again.";

    public function __construct(
        private readonly CloudflareDns $dns,
        private readonly CloudflareTunnels $tunnels,
        private readonly Cloudflared $cloudflared,
        private readonly TunnelHosts $hosts,
        private readonly CaddyApiClient $caddy,
        private readonly AddressResolver $resolver,
    ) {}

    public function preflight(#[SensitiveParameter] string $token, Server $server): ?string
    {
        try {
            $account = $this->account($token, array_keys($this->hosts->for($server)));

            return $account === null ? self::NO_ACCOUNT : $this->tunnels->refusal($token, $account);
        } catch (RuntimeException $failed) {
            return $failed->getMessage();
        }
    }

    public function open(ServerTunnel $tunnel, SSHConnection $connection): void
    {
        $server = $connection->server();
        $token = CloudflareConnection::token();

        if ($token === null) {
            $this->failed($tunnel, self::NO_TOKEN);

            return;
        }

        try {
            $this->route($tunnel, $connection, $server, $token);
        } catch (Throwable $failed) {
            $this->failed($tunnel, self::explain($failed, $server));
        }
    }

    public function close(ServerTunnel $tunnel, SSHConnection $connection): void
    {
        $server = $connection->server();
        $token = CloudflareConnection::token();
        $account = $tunnel->account_id;
        $id = $tunnel->tunnel_id;

        try {
            if ($token !== null && $account !== null && $id !== null) {
                $restore = self::restore($server);

                foreach ((array) $tunnel->hosts as $host) {
                    $zone = $this->dns->zone($token, $host);

                    if ($zone !== null) {
                        $this->dns->routeAway($token, $zone['id'], $host, (string) $tunnel->target(), $restore);
                    }
                }
            }

            $this->cloudflared->stop($connection);

            if ($token !== null && $account !== null && $id !== null) {
                rescue(fn() => $this->tunnels->delete($token, $account, $id), report: false);
            }

            $this->caddy->trustLoopback($connection, false);
        } catch (Throwable $failed) {
            $tunnel->forceFill(['error' => self::explain($failed, $server), 'checked_at' => now()])->save();

            return;
        }

        activity()->performedOn($server)->withProperties(['hosts' => (array) $tunnel->hosts])->log('tunnel_closed');
        $tunnel->delete();
    }

    private function route(ServerTunnel $tunnel, SSHConnection $connection, Server $server, #[SensitiveParameter] string $token): void
    {
        $wanted = $this->hosts->for($server);
        $zones = [];
        $skipped = [];

        foreach ($wanted as $host => $create) {
            $zone = $this->dns->zone($token, $host);

            if ($zone === null) {
                if ($create) {
                    $skipped[] = ['host' => $host, 'reason' => "Coresmith's Cloudflare token can't see the zone {$host} is in. Add that zone to the token in Cloudflare, then check again."];
                }

                continue;
            }

            $zones[$host] = $zone;
        }

        $account = $tunnel->account_id ?? $this->account($token, array_keys($zones));

        if ($account === null) {
            $this->failed($tunnel, self::NO_ACCOUNT);

            return;
        }

        $refusal = $this->tunnels->refusal($token, $account);

        if ($refusal !== null) {
            $this->failed($tunnel, $refusal);

            return;
        }

        $id = $tunnel->tunnel_id ?? $this->tunnels->ensure($token, $account, self::name($server));
        $tunnel->forceFill(['account_id' => $account, 'tunnel_id' => $id])->save();

        foreach ($zones as $host => $zone) {
            if ($zone['account'] !== '' && $zone['account'] !== $account) {
                $skipped[] = ['host' => $host, 'reason' => "{$host} is in a different Cloudflare account from the tunnel, so Coresmith left it alone."];
                unset($zones[$host]);
            }
        }

        $this->tunnels->configure($token, $account, $id, array_keys($zones));
        $this->cloudflared->start($connection, $this->tunnels->runToken($token, $account, $id));

        if (!$this->connected($token, $account, $id)) {
            $this->failed($tunnel, "Cloudflare's connector is running on {$server->name}, but Cloudflare hasn't seen it connect after a minute. Check that {$server->name} can reach the internet, then try again.");

            return;
        }

        $target = (string) $tunnel->target();
        $own = self::ownAddresses($server);
        $routed = [];

        foreach ($zones as $host => $zone) {
            $unclaimed = $this->unclaimed($host, $own);

            try {
                $reason = $this->dns->routeToTunnel($token, $zone['id'], $host, $target, $own, $wanted[$host] && $unclaimed);
            } catch (RuntimeException $refused) {
                $reason = "{$host}: {$refused->getMessage()}";
            }

            if ($reason === '' && $wanted[$host] && !$unclaimed) {
                $reason = "{$host} already answers from somewhere Coresmith can't see in Cloudflare, so Coresmith left it alone.";
            }

            if ($reason === null) {
                $routed[] = $host;
            } elseif ($reason !== '') {
                $skipped[] = ['host' => $host, 'reason' => $reason];
            }
        }

        foreach (array_diff((array) $tunnel->hosts, array_keys($wanted)) as $gone) {
            rescue(function () use ($token, $gone, $target): void {
                $zone = $this->dns->zone($token, $gone);

                if ($zone !== null) {
                    $this->dns->routeAway($token, $zone['id'], $gone, $target, []);
                }
            }, report: false);
        }

        $this->caddy->trustLoopback($connection, true);

        $opened = $tunnel->state !== ServerTunnel::OPEN;

        $tunnel->forceFill([
            'state' => self::unlessClosing($tunnel, ServerTunnel::OPEN),
            'hosts' => $routed,
            'skipped' => $skipped,
            'error' => null,
            'opened_at' => $tunnel->opened_at ?? now(),
            'checked_at' => now(),
        ])->save();

        if ($opened) {
            activity()->performedOn($server)->withProperties(['hosts' => $routed])->log('tunnel_opened');
        }
    }

    /**
     * @param  list<string>  $hosts
     */
    private function account(#[SensitiveParameter] string $token, array $hosts): ?string
    {
        foreach ($hosts as $host) {
            $account = $this->dns->zone($token, $host)['account'] ?? '';

            if ($account !== '') {
                return $account;
            }
        }

        return $this->tunnels->account($token);
    }

    /**
     * @param  list<string>  $own
     */
    private function unclaimed(string $host, array $own): bool
    {
        $mine = array_map(fn(string $address): string|false => @inet_pton($address), $own);

        return array_filter($this->resolver->addresses($host), fn(string $address): bool => !in_array(@inet_pton($address), $mine, true)) === [];
    }

    private function connected(#[SensitiveParameter] string $token, string $account, string $id): bool
    {
        for ($attempt = 0; $attempt < intdiv(self::CONNECT_SECONDS, self::POLL_SECONDS); $attempt++) {
            if (in_array($this->tunnels->status($token, $account, $id), ['healthy', 'degraded'], true)) {
                return true;
            }

            Sleep::for(self::POLL_SECONDS)->seconds();
        }

        return false;
    }

    private function failed(ServerTunnel $tunnel, string $error): void
    {
        $tunnel->forceFill([
            'state' => self::unlessClosing($tunnel, $tunnel->state === ServerTunnel::OPEN ? ServerTunnel::OPEN : ServerTunnel::FAILED),
            'error' => $error,
            'checked_at' => now(),
        ])->save();
    }

    private static function unlessClosing(ServerTunnel $tunnel, string $state): string
    {
        return ServerTunnel::query()->whereKey($tunnel->getKey())->value('state') === ServerTunnel::CLOSING ? ServerTunnel::CLOSING : $state;
    }

    public static function name(Server $server): string
    {
        return 'coresmith-' . (Str::slug($server->name) ?: 'server') . '-' . strtolower(substr((string) $server->getKey(), -6));
    }

    /**
     * @return list<string>
     */
    public static function ownAddresses(Server $server): array
    {
        $addresses = (array) $server->public_addresses;

        return array_values(array_filter([
            $server->ip_address,
            $addresses['ipv4'] ?? null,
            ...array_values((array) ($addresses['ipv6'] ?? [])),
        ], fn(mixed $address): bool => is_string($address) && filter_var($address, FILTER_VALIDATE_IP) !== false));
    }

    /**
     * @return list<array{type: string, value: string}>
     */
    private static function restore(Server $server): array
    {
        return array_values(array_map(
            fn(array $target): array => ['type' => $target['type'], 'value' => $target['value']],
            array_filter(ServerAddresses::targets($server->public_addresses), fn(array $target): bool => !$target['closed']),
        ));
    }

    private static function explain(Throwable $failed, Server $server): string
    {
        if ($failed instanceof UnsupportedOperatingSystemException) {
            return "Cloudflare's connector installs on Ubuntu, Debian, Rocky Linux, AlmaLinux, Fedora and Amazon Linux, and {$server->name} runs something else.";
        }

        if ($failed instanceof SSHCommandFailedException) {
            $said = Str::limit(trim((string) Str::of($failed->output())->trim()->explode("\n")->last()), 200);

            return "Coresmith couldn't set up Cloudflare's connector on {$server->name}." . ($said === '' ? '' : " The server said: {$said}") . ' Try again.';
        }

        if ($failed instanceof RuntimeException) {
            return $failed->getMessage();
        }

        report($failed);

        return "Something went wrong while Coresmith set up the tunnel on {$server->name}. Try again.";
    }
}
