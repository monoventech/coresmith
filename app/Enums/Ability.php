<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Enums;

enum Ability: string
{
    case ViewTeam = 'team.view';
    case UpdateTeam = 'team.update';
    case DeleteTeam = 'team.delete';
    case TransferOwnership = 'team.transfer';
    case ManageBilling = 'team.billing';
    case ManageMembers = 'team.members';

    case ViewServer = 'server.view';
    case CreateServer = 'server.create';
    case UpdateServer = 'server.update';
    case DeleteServer = 'server.delete';
    case ViewServerCredentials = 'server.credentials';

    case ViewApplication = 'application.view';
    case CreateApplication = 'application.create';
    case UpdateApplication = 'application.update';
    case DeleteApplication = 'application.delete';
    case DeployApplication = 'application.deploy';
    case ViewApplicationLogs = 'application.logs';
    case ManageApplicationEnv = 'application.env';

    case ViewDatabase = 'database.view';
    case CreateDatabase = 'database.create';
    case DeleteDatabase = 'database.delete';
    case BackupDatabase = 'database.backup';
    case RestoreDatabase = 'database.restore';
    case ViewDatabaseCredentials = 'database.credentials';

    case ManageProviders = 'provider.manage';
    case ManageFirewall = 'server.firewall';

    case ManageProjects = 'project.manage';

    public function coversWholeTeam(): bool
    {
        return $this === self::UpdateTeam || $this === self::ManageProviders;
    }
}
