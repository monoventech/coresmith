<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Moves;

use App\Enums\Edition;
use App\Models\Server;
use App\Models\Team;
use App\Services\Network\PublicAddress;

final class MoveReach
{
    public function refusal(Team $team, MoveTarget $target, bool $handOver = false): ?string
    {
        if ($target->edition->isCloud() && Edition::current()->isCloud()) {
            return 'That code comes from Coresmith Cloud. Paste a move code from your self-hosted Coresmith.';
        }

        foreach ($team->servers()->with('tunnel')->orderBy('name')->get() as $server) {
            $refusal = $this->serverRefusal($server, $target, $handOver);

            if ($refusal !== null) {
                return $refusal;
            }
        }

        return null;
    }

    public static function machineAddress(Server $server): ?string
    {
        $address = ((array) $server->public_addresses)['ipv4'] ?? null;

        return is_string($address) && PublicAddress::is($address) ? $address : null;
    }

    private function serverRefusal(Server $server, MoveTarget $target, bool $handOver): ?string
    {
        if ($server->runs_coresmith) {
            return $this->machineRefusal($server, $target, $handOver);
        }

        if (!$target->edition->isCloud()) {
            return null;
        }

        return match (true) {
            !PublicAddress::is((string) $server->ip_address) => "{$server->name} is at {$server->ip_address}, which Coresmith Cloud can't reach. Give it a public address or remove it from the team, then try again.",
            $server->tunnel !== null => self::tunnelRefusal($server),
            default => null,
        };
    }

    private function machineRefusal(Server $server, MoveTarget $target, bool $handOver): ?string
    {
        return match (true) {
            !$target->edition->isCloud() => "{$server->name} is the machine Coresmith runs on, which can't move to another Coresmith. Remove it from the team, then try again.",
            !$handOver => "{$server->name} is the machine Coresmith runs on. Agree to hand it over below, or remove it from the team, then try again.",
            $target->addresses === [] => 'That code is from before Coresmith Cloud could take over the machine Coresmith runs on. Create a new one in Coresmith Cloud, then try again.',
            self::machineAddress($server) === null => "{$server->name} has no public IPv4 address Coresmith Cloud can reach, so it can't move. Remove it from the team, then try again.",
            $server->tunnel !== null => self::tunnelRefusal($server),
            default => null,
        };
    }

    private static function tunnelRefusal(Server $server): string
    {
        return "{$server->name} uses IPv4 through Cloudflare, which only works on self-hosted Coresmith. Turn it off on the server's page, then try again.";
    }
}
