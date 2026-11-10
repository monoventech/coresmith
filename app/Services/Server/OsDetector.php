<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Server;

use App\Exceptions\UnsupportedOperatingSystemException;
use App\Services\SSH\SSHConnection;

final class OsDetector
{
    public const SUPPORTED = ['ubuntu', 'debian', 'amzn', 'rocky', 'almalinux', 'fedora'];

    public function detect(SSHConnection $connection): OsInfo
    {
        $release = $connection->execute('cat /etc/os-release');

        if ($release->failed()) {
            throw UnsupportedOperatingSystemException::forOs(null);
        }

        $values = $this->parseKeyValues($release->output);
        $name = strtolower($values['ID'] ?? '');

        if (!in_array($name, self::SUPPORTED, true)) {
            throw UnsupportedOperatingSystemException::forOs($name);
        }

        $systemd = $connection->execute('systemctl --version');

        if ($systemd->failed()) {
            throw UnsupportedOperatingSystemException::missingSystemd();
        }

        return new OsInfo(
            name: $name,
            version: $values['VERSION_ID'] ?? '',
            cpuCores: $this->cpuCores($connection),
            memoryMb: $this->memoryMb($connection),
            diskGb: $this->diskGb($connection),
            systemd: preg_match('/^systemd (\d+)\b/', $systemd->output, $matches) === 1 ? (int) $matches[1] : null,
        );
    }

    /**
     * @return array<string, string>
     */
    private function parseKeyValues(string $output): array
    {
        $values = [];

        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            if (!str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $values[trim($key)] = trim($value, " \t\"'");
        }

        return $values;
    }

    private function cpuCores(SSHConnection $connection): int
    {
        return max(1, (int) trim($connection->execute('nproc')->output));
    }

    private function memoryMb(SSHConnection $connection): int
    {
        $output = $connection->execute('free -m')->output;

        return preg_match('/^Mem:\s+(\d+)/m', $output, $matches) === 1 ? (int) $matches[1] : 0;
    }

    private function diskGb(SSHConnection $connection): int
    {
        $output = $connection->execute('df -BG /')->output;

        return preg_match('/\s(\d+)G\s+\d+G\s+\d+G\s/', $output, $matches) === 1 ? (int) $matches[1] : 0;
    }
}
