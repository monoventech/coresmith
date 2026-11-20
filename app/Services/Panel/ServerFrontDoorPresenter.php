<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Panel;

use App\Enums\Edition;
use App\Enums\ServerStatus;
use App\Enums\TeamRole;
use App\Http\Controllers\ServerFrontDoorController;
use App\Models\Server;
use App\Models\User;
use App\Services\Address\CloudflareConnection;
use Carbon\CarbonImmutable;

final class ServerFrontDoorPresenter
{
    /**
     * @return array{state: string, doors: list<array{key: string, name: string, ipv4: string}>, door: ?array{name: string, url: string}, hosts: list<string>, skipped: list<array{host: string, reason: string}>, failing: list<array{host: string, reason: string}>, records: list<array{type: string, name: string, value: string}>, error: ?string, checked: ?string, tunnel_on: bool, automatic_dns: bool, can_manage: bool}|null
     */
    public function present(Server $server, User $user): ?array
    {
        $frontDoor = $server->frontDoor()->with('door')->first();

        if ($frontDoor === null && (((array) $server->public_addresses)['ipv4_open'] ?? null) !== false) {
            return null;
        }

        $door = $frontDoor?->door;
        $canManage = $user->roleIn($server->team) === TeamRole::Owner && $user->can('update', $server);
        $failing = array_values(array_filter((array) ($frontDoor?->failing ?? []), fn(mixed $row): bool => is_array($row) && is_string($row['host'] ?? null) && is_string($row['reason'] ?? null)));

        return [
            'state' => $frontDoor === null ? 'off' : (string) $frontDoor->state,
            'doors' => $frontDoor === null && $canManage ? self::doors($server) : [],
            'door' => $door === null ? null : ['name' => $door->name, 'url' => route('servers.show', $door)],
            'hosts' => array_values(array_filter((array) ($frontDoor?->hosts ?? []), is_string(...))),
            'skipped' => array_values(array_filter((array) ($frontDoor?->skipped ?? []), fn(mixed $row): bool => is_array($row) && is_string($row['host'] ?? null) && is_string($row['reason'] ?? null))),
            'failing' => array_map(fn(array $row): array => ['host' => $row['host'], 'reason' => $row['reason']], $failing),
            'records' => $door === null ? [] : self::records($door, $failing),
            'error' => $frontDoor?->error,
            'checked' => $frontDoor?->checked_at === null ? null : Formats::when(CarbonImmutable::instance($frontDoor->checked_at)),
            'tunnel_on' => $server->tunnel()->exists(),
            'automatic_dns' => Edition::current()->isSelfHosted() && CloudflareConnection::token() !== null,
            'can_manage' => $canManage,
        ];
    }

    /**
     * @return list<array{key: string, name: string, ipv4: string}>
     */
    private static function doors(Server $home): array
    {
        return Server::query()
            ->where('team_id', $home->team_id)
            ->whereKeyNot($home->getKey())
            ->where('status', ServerStatus::Active->value)
            ->orderBy('name')
            ->get()
            ->filter(fn(Server $door): bool => ServerFrontDoorController::eligible($home, $door) && is_string(((array) $door->public_addresses)['ipv4'] ?? null))
            ->map(fn(Server $door): array => ['key' => (string) $door->getKey(), 'name' => $door->name, 'ipv4' => (string) ((array) $door->public_addresses)['ipv4']])
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $failing
     * @return list<array{type: string, name: string, value: string}>
     */
    private static function records(Server $door, array $failing): array
    {
        $addresses = (array) $door->public_addresses;
        $ipv4 = is_string($addresses['ipv4'] ?? null) ? $addresses['ipv4'] : null;
        $ipv6 = array_values(array_filter((array) ($addresses['ipv6'] ?? []), is_string(...)))[0] ?? null;
        $records = [];

        foreach ($failing as $row) {
            if (($row['kind'] ?? null) !== 'dns') {
                continue;
            }

            if ($ipv4 !== null) {
                $records[] = ['type' => 'A', 'name' => (string) $row['host'], 'value' => $ipv4];
            }

            if ($ipv6 !== null) {
                $records[] = ['type' => 'AAAA', 'name' => (string) $row['host'], 'value' => $ipv6];
            }
        }

        return $records;
    }
}
