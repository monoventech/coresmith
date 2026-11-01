<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Panel;

use App\Enums\Ability;
use App\Enums\CloudProviderType;
use App\Models\CloudProvider;
use App\Models\Team;
use App\Models\User;

final class CloudPresenter
{
    /**
     * @return array{accounts: list<array{id: string, type: string, provider: string, name: string, label: ?string, refused: bool, refused_on: ?string, servers: int}>, types: list<array{value: string, label: string, help: string, token_url: string, permission: string}>, can_manage: bool}
     */
    public function accounts(Team $team, User $user): array
    {
        return [
            'accounts' => CloudProvider::query()
                ->where('team_id', $team->getKey())
                ->withCount('servers')
                ->oldest()
                ->orderBy('id')
                ->get()
                ->map(fn(CloudProvider $account): array => [
                    'id' => (string) $account->getKey(),
                    'type' => $account->type->value,
                    'provider' => $account->type->label(),
                    'name' => (string) $account->name,
                    'label' => $account->account_label,
                    'refused' => $account->isRefused(),
                    'refused_on' => $account->token_failed_at?->format('M j'),
                    'servers' => (int) $account->servers_count,
                ])
                ->values()
                ->all(),
            'types' => self::types(),
            'can_manage' => $user->hasTeamAbility($team, Ability::ManageProviders),
        ];
    }

    /**
     * @return list<array{value: string, label: string, help: string, token_url: string, permission: string}>
     */
    public static function types(): array
    {
        return array_map(fn(CloudProviderType $type): array => [
            'value' => $type->value,
            'label' => $type->label(),
            'help' => $type->tokenHelp(),
            'token_url' => $type->tokenUrl(),
            'permission' => $type->permissionHint(),
        ], CloudProviderType::cases());
    }
}
