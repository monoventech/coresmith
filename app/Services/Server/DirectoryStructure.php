<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Server;

use App\Services\Runtime\AsdfManager;
use App\Services\SSH\SSHConnection;

final class DirectoryStructure
{
    private const ROOT_ONLY = [
        '/etc/coresmith/env',
        '/etc/coresmith/deploy-keys',
    ];

    private const SHARED = [
        '/etc/coresmith',
        '/var/coresmith/apps',
        '/var/coresmith/logs',
        '/var/coresmith/backups',
        '/var/coresmith/scripts',
    ];

    public function create(SSHConnection $connection): void
    {
        $user = AsdfManager::SYSTEM_USER;

        foreach (self::SHARED as $directory) {
            $connection->execute(
                "mkdir -p {$directory} && chown root:{$user} {$directory} && chmod 750 {$directory}",
                sudo: true,
            )->throw();
        }

        foreach (self::ROOT_ONLY as $directory) {
            $connection->execute(
                "mkdir -p {$directory} && chown root:root {$directory} && chmod 700 {$directory}",
                sudo: true,
            )->throw();
        }
    }
}
