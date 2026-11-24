<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Panel;

use App\Models\Server;
use App\Models\ServerFrontDoor;
use App\Models\ServerTunnel;
use App\Models\User;
use Carbon\CarbonImmutable;

final class ServerNetworkPresenter
{
    /**
     * @return array{rows: list<array{label: string, values: list<string>, note: string|null, tone: string|null}>, checked: string|null}
     */
    public function addresses(Server $server): array
    {
        $addresses = (array) $server->public_addresses;
        $ipv4 = is_string($addresses['ipv4'] ?? null) ? $addresses['ipv4'] : null;
        $private = array_values(array_filter(
            array_map(fn(mixed $cidr): string => explode('/', (string) $cidr)[0], (array) ($addresses['private'] ?? [])),
            fn(string $address): bool => $address !== '' && $address !== $server->ip_address,
        ));
        $checked = is_string($addresses['checked_at'] ?? null) ? CarbonImmutable::parse($addresses['checked_at']) : null;

        return [
            'rows' => array_values(array_filter([
                ['label' => 'IPv4', 'values' => $ipv4 === null ? [] : [$ipv4], ...$this->ipv4($server, $ipv4 === null ? null : ($addresses['ipv4_open'] ?? null))],
                ['label' => 'IPv6', 'values' => $this->ipv6($server), 'note' => null, 'tone' => null],
                $private === [] ? null : ['label' => 'Private', 'values' => $private, 'note' => null, 'tone' => null],
            ])),
            'checked' => Formats::when($checked),
        ];
    }

    /**
     * @return array{text: string, action: string, href: string}|null
     */
    public function notice(Server $server, User $user): ?array
    {
        [$tunnel, $frontDoor] = $this->ways($server);
        $ipv6 = $this->ipv6($server) !== [];
        $who = $ipv6 ? 'people without IPv6' : 'visitors';
        $href = route('servers.network', $server);

        return match (true) {
            $tunnel?->state === ServerTunnel::FAILED => ['text' => "IPv4 through Cloudflare didn't finish, so {$who} can't open the apps here.", 'action' => 'See what went wrong', 'href' => $href],
            $frontDoor?->state === ServerFrontDoor::FAILED => ['text' => "IPv4 through {$frontDoor->door?->name} didn't finish, so {$who} can't open the apps here.", 'action' => 'See what went wrong', 'href' => $href],
            $tunnel === null && $frontDoor === null && (((array) $server->public_addresses)['ipv4_open'] ?? null) === false => [
                'text' => $ipv6
                    ? "Visitors can't reach this server's IPv4 address from the internet, so people without IPv6 can't open the apps here."
                    : "Visitors can't reach this server from the internet. Its IPv4 address doesn't answer, and it has no IPv6 address.",
                'action' => $user->can('update', $server) ? 'Bring IPv4 visitors in' : 'See the Network tab',
                'href' => $href,
            ],
            default => null,
        };
    }

    /**
     * @return array{note: string|null, tone: string|null}
     */
    private function ipv4(Server $server, mixed $open): array
    {
        [$tunnel, $frontDoor] = $this->ways($server);

        $carrier = match (true) {
            $tunnel?->state === ServerTunnel::OPEN => 'Cloudflare',
            $frontDoor?->state === ServerFrontDoor::OPEN => $frontDoor->door?->name,
            default => null,
        };

        return match (true) {
            $open === true => ['note' => 'Visitors reach it from the internet.', 'tone' => 'success'],
            $open === false && $carrier !== null => ['note' => "Visitors can't reach it from the internet, so they come in through {$carrier}.", 'tone' => 'success'],
            $open === false => ['note' => "Visitors can't reach it from the internet.", 'tone' => 'warning'],
            default => ['note' => null, 'tone' => null],
        };
    }

    /**
     * @return list<string>
     */
    private function ipv6(Server $server): array
    {
        return array_values(array_filter((array) (((array) $server->public_addresses)['ipv6'] ?? []), is_string(...)));
    }

    /**
     * @return array{0: ServerTunnel|null, 1: ServerFrontDoor|null}
     */
    private function ways(Server $server): array
    {
        return [$server->tunnel()->first(), $server->frontDoor()->with('door')->first()];
    }
}
