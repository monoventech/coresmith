<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Enums;

enum TeamRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Developer = 'developer';
    case Member = 'member';

    /**
     * @return list<Ability>
     */
    public function abilities(): array
    {
        return match ($this) {
            self::Owner => Ability::cases(),
            self::Admin => array_values(array_filter(
                Ability::cases(),
                static fn(Ability $ability): bool => !in_array($ability, [
                    Ability::DeleteTeam,
                    Ability::TransferOwnership,
                    Ability::ManageBilling,
                ], true),
            )),
            self::Developer => [
                Ability::ViewTeam,
                Ability::ViewServer,
                Ability::CreateServer,
                Ability::UpdateServer,
                Ability::DeleteServer,
                Ability::ManageFirewall,
                Ability::ViewApplication,
                Ability::CreateApplication,
                Ability::UpdateApplication,
                Ability::DeleteApplication,
                Ability::DeployApplication,
                Ability::ViewApplicationLogs,
                Ability::ManageApplicationEnv,
                Ability::ViewDatabase,
                Ability::CreateDatabase,
                Ability::DeleteDatabase,
                Ability::BackupDatabase,
                Ability::RestoreDatabase,
                Ability::ViewDatabaseCredentials,
            ],
            self::Member => [
                Ability::ViewTeam,
                Ability::ViewServer,
                Ability::ViewApplication,
                Ability::DeployApplication,
                Ability::ViewApplicationLogs,
                Ability::ViewDatabase,
            ],
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Owner',
            self::Admin => 'Admin',
            self::Developer => 'Developer',
            self::Member => 'Deployer',
        };
    }

    public function can(Ability $ability): bool
    {
        return in_array($ability, $this->abilities(), true);
    }
}
