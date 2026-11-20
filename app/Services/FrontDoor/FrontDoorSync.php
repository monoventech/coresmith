<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\FrontDoor;

use App\Enums\Edition;
use App\Exceptions\SSHCommandFailedException;
use App\Exceptions\UnsupportedOperatingSystemException;
use App\Models\Server;
use App\Models\ServerFrontDoor;
use App\Services\Address\CloudflareConnection;
use App\Services\Address\CloudflareDns;
use App\Services\Caddy\CaddyApiClient;
use App\Services\Network\ServerAddresses;
use App\Services\Server\FirewallManagerFactory;
use App\Services\SSH\SSHConnection;
use App\Services\Tunnel\EdgeRedirects;
use App\Services\Tunnel\TunnelHosts;
use App\Services\Tunnel\TunnelSync;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class FrontDoorSync
{
    public const HANDSHAKE_SECONDS = 60;

    public const POLL_SECONDS = 3;

    public const FRESH_HANDSHAKE_SECONDS = 180;

    public function __construct(
        private readonly WireGuard $wireGuard,
        private readonly CaddyApiClient $caddy,
        private readonly CloudflareDns $dns,
        private readonly TunnelHosts $hosts,
        private readonly EdgeRedirects $web,
    ) {}

    public function open(ServerFrontDoor $frontDoor, SSHConnection $home, SSHConnection $door): void
    {
        try {
            $this->link($frontDoor, $home, $door);
        } catch (Throwable $failed) {
            $this->failed($frontDoor, self::explain($failed, $door->server()));
        }
    }

    public function close(ServerFrontDoor $frontDoor, ?SSHConnection $home, ?SSHConnection $door): void
    {
        $incomplete = [];
        $step = function (string $name, callable $work) use (&$incomplete): void {
            try {
                $work();
            } catch (Throwable) {
                $incomplete[] = $name;
            }
        };
        $homeServer = $frontDoor->home;
        $doorServer = $frontDoor->door;

        if ($homeServer !== null && $doorServer !== null) {
            $step('dns', fn() => $this->pointBack($frontDoor, $homeServer, $doorServer));
        }

        if ($door !== null && $doorServer !== null) {
            $others = $this->doorPeers($frontDoor, without: true);

            $step('door route', fn() => $this->caddy->removeDoor($door, $frontDoor));
            $step('door link', fn() => $others === [] ? $this->wireGuard->remove($door) : $this->wireGuard->configure($door, (string) $frontDoor->door_address, ServerFrontDoor::PORT, $others));

            if ($others === []) {
                $step('door port', fn() => $door->execute(FirewallManagerFactory::for($doorServer)->remove(ServerFrontDoor::PORT, 'udp'), sudo: true)->throw());
            }
        } else {
            $incomplete[] = 'door';
        }

        if ($home !== null) {
            $step('home trust', fn() => $this->caddy->trustProxies($home, []));
            $step('home link', fn() => $this->wireGuard->remove($home));
        } else {
            $incomplete[] = 'home';
        }

        if ($homeServer !== null) {
            activity()->performedOn($homeServer)->withProperties(['hosts' => (array) $frontDoor->hosts, 'door' => $doorServer?->name])->log('front_door_closed');

            if ($incomplete !== []) {
                activity()->performedOn($homeServer)->withProperties(['steps' => $incomplete])->log('front_door_close_incomplete');
            }
        }

        $frontDoor->delete();
    }

    private function link(ServerFrontDoor $frontDoor, SSHConnection $home, SSHConnection $door): void
    {
        $homeServer = $home->server();
        $doorServer = $door->server();
        $ipv4 = self::ipv4($doorServer);

        if ($ipv4 === null) {
            $this->failed($frontDoor, "Coresmith doesn't know {$doorServer->name}'s public IPv4 address yet. Try again in an hour.");

            return;
        }

        $this->wireGuard->install($door);
        $this->wireGuard->install($home);

        $clash = $this->wireGuard->clash($door, (string) $frontDoor->subnet, ServerFrontDoor::PORT) ?? $this->wireGuard->clash($home, (string) $frontDoor->subnet, null);

        if ($clash !== null) {
            $this->failed($frontDoor, $clash);

            return;
        }

        $frontDoor->forceFill(['door_public_key' => $this->wireGuard->publicKey($door), 'home_public_key' => $this->wireGuard->publicKey($home)])->save();

        $this->wireGuard->configure($door, (string) $frontDoor->door_address, ServerFrontDoor::PORT, $this->doorPeers($frontDoor));
        $door->execute(FirewallManagerFactory::for($doorServer)->allow(ServerFrontDoor::PORT, 'udp'), sudo: true)->throw();
        $this->wireGuard->configure($home, (string) $frontDoor->home_address, null, [[
            'key' => (string) $frontDoor->door_public_key,
            'allowed' => "{$frontDoor->door_address}/32",
            'endpoint' => "{$ipv4}:" . ServerFrontDoor::PORT,
            'keepalive' => 25,
        ]]);

        if (!$this->shook($door, (string) $frontDoor->home_public_key)) {
            $this->failed($frontDoor, "{$doorServer->name} didn't answer over WireGuard. Allow UDP " . ServerFrontDoor::PORT . " into {$doorServer->name} ({$ipv4}) in your provider's firewall, then try again.");

            return;
        }

        $this->caddy->trustProxies($home, ["{$frontDoor->door_address}/32"]);

        $wanted = $this->hosts->for($homeServer);
        $names = array_keys($wanted);

        $this->caddy->putDoor($door, $frontDoor, $names);

        $skipped = $this->point($homeServer, $doorServer, $wanted);
        [$failing, $carried] = $this->check($wanted, $homeServer, $doorServer, $ipv4);
        $opened = $frontDoor->state !== ServerFrontDoor::OPEN;

        $frontDoor->forceFill([
            'state' => self::unlessClosing($frontDoor, ServerFrontDoor::OPEN),
            'hosts' => array_values(array_diff($carried, array_column($skipped, 'host'))),
            'skipped' => $skipped,
            'failing' => $failing,
            'error' => null,
            'opened_at' => $frontDoor->opened_at ?? now(),
            'checked_at' => now(),
        ])->save();

        if ($opened) {
            activity()->performedOn($homeServer)->withProperties(['door' => $doorServer->name, 'hosts' => $names])->log('front_door_opened');
        }
    }

    private function shook(SSHConnection $door, string $homeKey): bool
    {
        for ($attempt = 0; $attempt <= intdiv(self::HANDSHAKE_SECONDS, self::POLL_SECONDS); $attempt++) {
            $age = $this->wireGuard->handshakeAge($door, $homeKey);

            if ($age !== null && $age <= self::FRESH_HANDSHAKE_SECONDS) {
                return true;
            }

            Sleep::for(self::POLL_SECONDS)->seconds();
        }

        return false;
    }

    /**
     * @param  array<string, bool>  $wanted
     * @return list<array{host: string, reason: string}>
     */
    private function point(Server $home, Server $door, array $wanted): array
    {
        $token = Edition::current()->isSelfHosted() ? CloudflareConnection::token() : null;

        if ($token === null) {
            return [];
        }

        $skipped = [];
        $targets = self::targets($door);
        $own = TunnelSync::ownAddresses($home);

        foreach ($wanted as $host => $create) {
            try {
                $zone = $this->dns->zone($token, $host);

                if ($zone === null) {
                    continue;
                }

                $reason = $this->dns->pointAtDoor($token, $zone['id'], $host, $targets, $own, $create);
            } catch (RuntimeException $refused) {
                $reason = "{$host}: {$refused->getMessage()}";
            }

            if (is_string($reason) && $reason !== '') {
                $skipped[] = ['host' => $host, 'reason' => $reason];
            }
        }

        return $skipped;
    }

    /**
     * @param  array<string, bool>  $wanted
     * @return array{0: list<array{host: string, reason: string}>, 1: list<string>}
     */
    private function check(array $wanted, Server $home, Server $door, string $ipv4): array
    {
        $failing = [];
        $carried = [];

        foreach ($wanted as $host => $create) {
            $answers = $this->web->addresses($host, 'A');

            if ($answers === [] && !$create) {
                continue;
            }

            if (!in_array($ipv4, $answers, true)) {
                $failing[] = ['host' => $host, 'reason' => "{$host} doesn't point at {$door->name} yet."];

                continue;
            }

            $status = $this->web->reach($host, $ipv4);

            if ($status === null || in_array($status, [502, 504], true)) {
                $failing[] = ['host' => $host, 'reason' => "{$host} didn't load through {$door->name}. Check that its app is deployed on {$home->name}."];

                continue;
            }

            $carried[] = $host;
        }

        return [$failing, $carried];
    }

    private function pointBack(ServerFrontDoor $frontDoor, Server $home, Server $door): void
    {
        $token = Edition::current()->isSelfHosted() ? CloudflareConnection::token() : null;

        if ($token === null) {
            return;
        }

        $doorAddresses = array_column(self::targets($door), 'value');
        $restore = array_values(array_map(
            fn(array $target): array => ['type' => $target['type'], 'value' => $target['value']],
            array_filter(ServerAddresses::targets($home->public_addresses), fn(array $target): bool => !$target['closed']),
        ));

        foreach ((array) $frontDoor->hosts as $host) {
            $zone = $this->dns->zone($token, (string) $host);

            if ($zone !== null) {
                $this->dns->pointBack($token, $zone['id'], (string) $host, $doorAddresses, $restore);
            }
        }
    }

    /**
     * @return list<array{key: string, allowed: string}>
     */
    private function doorPeers(ServerFrontDoor $frontDoor, bool $without = false): array
    {
        return ServerFrontDoor::query()
            ->where('door_server_id', $frontDoor->door_server_id)
            ->whereNotNull('home_public_key')
            ->when($without, fn($query) => $query->whereKeyNot($frontDoor->getKey()))
            ->orderBy('home_address')
            ->get()
            ->map(fn(ServerFrontDoor $row): array => ['key' => (string) $row->home_public_key, 'allowed' => "{$row->home_address}/32"])
            ->values()
            ->all();
    }

    private function failed(ServerFrontDoor $frontDoor, string $error): void
    {
        $frontDoor->forceFill([
            'state' => self::unlessClosing($frontDoor, $frontDoor->state === ServerFrontDoor::OPEN ? ServerFrontDoor::OPEN : ServerFrontDoor::FAILED),
            'error' => $error,
            'checked_at' => now(),
        ])->save();
    }

    private static function unlessClosing(ServerFrontDoor $frontDoor, string $state): string
    {
        return ServerFrontDoor::query()->whereKey($frontDoor->getKey())->value('state') === ServerFrontDoor::CLOSING ? ServerFrontDoor::CLOSING : $state;
    }

    private static function ipv4(Server $server): ?string
    {
        $ipv4 = ((array) $server->public_addresses)['ipv4'] ?? null;

        return is_string($ipv4) && filter_var($ipv4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? $ipv4 : null;
    }

    /**
     * @return list<array{type: string, value: string}>
     */
    private static function targets(Server $door): array
    {
        $targets = [];
        $ipv4 = self::ipv4($door);
        $ipv6 = array_values(array_filter((array) (((array) $door->public_addresses)['ipv6'] ?? []), fn(mixed $address): bool => is_string($address) && filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false))[0] ?? null;

        if ($ipv4 !== null) {
            $targets[] = ['type' => 'A', 'value' => $ipv4];
        }

        if ($ipv6 !== null) {
            $targets[] = ['type' => 'AAAA', 'value' => $ipv6];
        }

        return $targets;
    }

    private static function explain(Throwable $failed, Server $door): string
    {
        if ($failed instanceof UnsupportedOperatingSystemException) {
            return 'WireGuard installs on Ubuntu, Debian, Rocky Linux, AlmaLinux, Fedora and Amazon Linux, and one of these servers runs something else.';
        }

        if ($failed instanceof SSHCommandFailedException) {
            $said = Str::limit(trim((string) Str::of($failed->output())->trim()->explode("\n")->last()), 200);

            return "Coresmith couldn't finish linking the servers through {$door->name}." . ($said === '' ? '' : " The server said: {$said}") . ' Try again.';
        }

        if ($failed instanceof RuntimeException) {
            return $failed->getMessage();
        }

        report($failed);

        return "Something went wrong while Coresmith linked the servers through {$door->name}. Try again.";
    }
}
