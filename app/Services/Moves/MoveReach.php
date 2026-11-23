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
    public function refusal(Team $team, MoveTarget $target): ?string
    {
        if (!$target->edition->isCloud()) {
            return null;
        }

        if (Edition::current()->isCloud()) {
            return 'That code comes from Coresmith Cloud. Paste a move code from your self-hosted Coresmith.';
        }

        foreach ($team->servers()->with('tunnel')->orderBy('name')->get() as $server) {
            $refusal = $this->serverRefusal($server);

            if ($refusal !== null) {
                return $refusal;
            }
        }

        return null;
    }

    private function serverRefusal(Server $server): ?string
    {
        return match (true) {
            (bool) $server->runs_coresmith => "{$server->name} is the machine Coresmith runs on, which can't move to Coresmith Cloud. Remove it from the team, then try again.",
            !PublicAddress::is((string) $server->ip_address) => "{$server->name} is at {$server->ip_address}, which Coresmith Cloud can't reach. Give it a public address or remove it from the team, then try again.",
            $server->tunnel !== null => "{$server->name} uses IPv4 through Cloudflare, which only works on self-hosted Coresmith. Turn it off on the server's page, then try again.",
            default => null,
        };
    }
}
