<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Server;

use App\Services\SSH\SSHConnection;

final class PortScanner
{
    public const COMMAND = 'ss -tlnpH 2>/dev/null';

    /**
     * @return array<int, string>
     */
    public function listeners(SSHConnection $connection): array
    {
        return $this->parse($connection->execute(self::COMMAND, sudo: true)->output);
    }

    /**
     * @return array<int, string>
     */
    public function parse(string $output): array
    {
        $listeners = [];

        foreach (preg_split('/\R/', trim($output)) ?: [] as $line) {
            $fields = preg_split('/\s+/', trim($line)) ?: [];

            if (count($fields) < 4 || preg_match('/:(\d{1,5})$/', $fields[3], $port) !== 1) {
                continue;
            }

            $listeners[(int) $port[1]] ??= preg_match('/users:\(\("([^"]+)"/', $line, $name) === 1 ? $name[1] : 'an unknown process';
        }

        ksort($listeners);

        return $listeners;
    }
}
