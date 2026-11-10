<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Server;

use App\Services\SSH\SSHConnection;

final class DirectoryStructure
{
    private const ROOT_ONLY = [
        '/etc/coresmith/env',
        '/etc/coresmith/deploy-keys',
        '/etc/coresmith/blueprints',
    ];

    private const SHARED = [
        '/etc/coresmith' => 750,
        '/var/coresmith/apps' => 751,
        '/var/coresmith/logs' => 750,
        '/var/coresmith/backups' => 750,
        '/var/coresmith/scripts' => 750,
    ];

    public function create(SSHConnection $connection): void
    {
        $user = $connection->server()->runtimeUser();

        foreach (self::SHARED as $directory => $mode) {
            $connection->execute(
                "mkdir -p {$directory} && chown root:{$user} {$directory} && chmod {$mode} {$directory}",
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
