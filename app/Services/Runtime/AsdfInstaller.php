<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Runtime;

use App\Services\SSH\SSHConnection;

final class AsdfInstaller
{
    public const ASDF_DIR = '/opt/asdf';

    public const ASDF_VERSION = 'v0.14.1';

    public function install(SSHConnection $connection): void
    {
        $this->createSystemUser($connection);
        $this->cloneAsdf($connection);
        $this->createDataDirectory($connection);
        $this->configureShell($connection);
        $this->addPlugins($connection);
        $this->verify($connection);
    }

    private function createSystemUser(SSHConnection $connection): void
    {
        $user = AsdfManager::SYSTEM_USER;

        $connection->execute(
            "id -u {$user} >/dev/null 2>&1 || useradd --system --create-home --home-dir /home/{$user} --shell /bin/bash {$user}",
            sudo: true,
        )->throw();
    }

    private function cloneAsdf(SSHConnection $connection): void
    {
        $dir = self::ASDF_DIR;
        $user = AsdfManager::SYSTEM_USER;

        $connection->execute(
            "test -d {$dir}/.git || git clone https://github.com/asdf-vm/asdf.git {$dir} --branch " . self::ASDF_VERSION,
            sudo: true,
        )->throw();

        $connection->execute("chown -R {$user}:{$user} {$dir} && chmod 755 {$dir}", sudo: true)->throw();
    }

    private function createDataDirectory(SSHConnection $connection): void
    {
        $data = AsdfManager::DATA_DIR;
        $user = AsdfManager::SYSTEM_USER;

        $connection->execute(
            "mkdir -p {$data} && chown -R {$user}:{$user} {$data} && chmod 755 {$data}",
            sudo: true,
        )->throw();
    }

    private function configureShell(SSHConnection $connection): void
    {
        $user = AsdfManager::SYSTEM_USER;
        $bashrc = "/home/{$user}/.bashrc";

        $exports = implode("\n", [
            'export ASDF_DIR="' . self::ASDF_DIR . '"',
            'export ASDF_DATA_DIR="' . AsdfManager::DATA_DIR . '"',
            '. "$ASDF_DIR/asdf.sh"',
        ]);

        $connection->execute(
            "grep -q ASDF_DATA_DIR {$bashrc} 2>/dev/null || printf '%s\\n' " . escapeshellarg($exports) . " >> {$bashrc}",
            sudo: true,
        )->throw();
    }

    private function addPlugins(SSHConnection $connection): void
    {
        foreach (AsdfManager::PLUGINS as $plugin) {
            $command = "asdf plugin list | grep -qx {$plugin} || asdf plugin add {$plugin}";

            $connection->execute(AsdfManager::asSystemUser($command), sudo: true)->throw();
        }
    }

    private function verify(SSHConnection $connection): void
    {
        $connection->execute(AsdfManager::asSystemUser('asdf --version'), sudo: true)->throw();
    }
}
