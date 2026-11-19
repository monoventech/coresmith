<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Panel;

use App\Enums\Edition;
use App\Models\Server;
use App\Models\User;
use App\Services\Address\CloudflareConnection;
use Carbon\CarbonImmutable;

final class ServerTunnelPresenter
{
    /**
     * @return array{state: string, ipv4: ?string, hosts: list<string>, skipped: list<array{host: string, reason: string}>, error: ?string, checked: ?string, cloudflare: bool, can_manage: bool}|null
     */
    public function present(Server $server, User $user): ?array
    {
        if (!Edition::current()->isSelfHosted()) {
            return null;
        }

        $tunnel = $server->tunnel()->first();
        $addresses = (array) $server->public_addresses;

        if ($tunnel === null && ($addresses['ipv4_open'] ?? null) !== false) {
            return null;
        }

        return [
            'state' => $tunnel === null ? 'off' : $tunnel->state,
            'ipv4' => is_string($addresses['ipv4'] ?? null) ? $addresses['ipv4'] : null,
            'hosts' => array_values(array_filter((array) ($tunnel?->hosts ?? []), is_string(...))),
            'skipped' => array_values(array_filter((array) ($tunnel?->skipped ?? []), fn(mixed $row): bool => is_array($row) && is_string($row['host'] ?? null) && is_string($row['reason'] ?? null))),
            'error' => $tunnel?->error,
            'checked' => $tunnel?->checked_at === null ? null : Formats::when(CarbonImmutable::instance($tunnel->checked_at)),
            'cloudflare' => CloudflareConnection::token() !== null,
            'can_manage' => $user->managesInstance() && $user->can('update', $server),
        ];
    }
}
