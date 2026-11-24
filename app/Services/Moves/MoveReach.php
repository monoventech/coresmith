<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Moves;

use App\Enums\Edition;
use App\Models\Server;
use App\Models\Team;
use App\Services\Network\LocalAddresses;
use App\Services\Network\PublicAddress;

final class MoveReach
{
    public function __construct(private readonly LocalAddresses $local) {}

    /**
     * @return array<string, string>
     */
    public function refusal(Team $team, MoveTarget $target, bool $handOver = false): array
    {
        if ($target->edition->isCloud() && Edition::current()->isCloud()) {
            return ['code' => 'That code comes from Coresmith Cloud. Paste a move code from your self-hosted Coresmith.'];
        }

        foreach ($team->servers()->with('tunnel')->orderBy('name')->get() as $server) {
            $refusal = $server->runs_coresmith ? $this->machineRefusal($server, $target, $handOver) : $this->serverRefusal($server, $target);

            if ($refusal !== []) {
                return $refusal;
            }
        }

        return [];
    }

    public static function machineAddress(Server $server): ?string
    {
        $address = ((array) $server->public_addresses)['ipv4'] ?? null;

        return is_string($address) && PublicAddress::is($address) ? $address : null;
    }

    /**
     * @return array<string, string>
     */
    private function serverRefusal(Server $server, MoveTarget $target): array
    {
        if (!$target->edition->isCloud()) {
            return [];
        }

        return match (true) {
            !PublicAddress::is((string) $server->ip_address) => ['code' => "{$server->name} is at {$server->ip_address}, which Coresmith Cloud can't reach. Give it a public address or remove it from the team, then try again."],
            $server->tunnel !== null => ['code' => self::tunnelRefusal($server)],
            default => [],
        };
    }

    /**
     * @return array<string, string>
     */
    private function machineRefusal(Server $server, MoveTarget $target, bool $handOver): array
    {
        return match (true) {
            !$target->edition->isCloud() => ['code' => "{$server->name} is the machine Coresmith runs on, which can't move to another Coresmith. Remove it from the team, then try again."],
            !$handOver => ['hand_over' => "{$server->name} is the machine Coresmith runs on. Agree to hand it over, or remove it from the team, then try again."],
            $target->addresses === [] => ['code' => 'That code is from before Coresmith Cloud could take over the machine Coresmith runs on. Create a new one in Coresmith Cloud, then try again.'],
            self::machineAddress($server) === null => ['hand_over' => "{$server->name} has no public IPv4 address Coresmith Cloud can reach, so it can't move. Remove it from the team, then try again."],
            !$this->answersOutside($server) => ['hand_over' => "{$server->name} reaches the internet through another device, such as a home router, so Coresmith Cloud can't reach it. Remove it from the team, then try again."],
            $server->tunnel !== null => ['hand_over' => self::tunnelRefusal($server)],
            default => [],
        };
    }

    private function answersOutside(Server $server): bool
    {
        return in_array(self::machineAddress($server), $this->local->all(), true) || (((array) $server->public_addresses)['ipv4_open'] ?? null) === true;
    }

    private static function tunnelRefusal(Server $server): string
    {
        return "{$server->name} uses IPv4 through Cloudflare, which only works on self-hosted Coresmith. Turn it off on the server's Network tab, then try again.";
    }
}
