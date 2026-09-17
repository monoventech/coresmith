<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Server;

use App\Services\SSH\SSHConnection;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

final class OomKillReader
{
    public const LOOKBACK_SECONDS = 120;

    /**
     * @return list<array{unit: string, at: CarbonImmutable, message: string}>
     */
    public function read(SSHConnection $connection, CarbonInterface $since): array
    {
        $command = "journalctl --since '@" . $since->getTimestamp() . "' --no-pager -o json -u 'coresmith-app-*' 2>/dev/null | grep -i 'oom' || true";

        return $this->parse($connection->execute($command, sudo: true)->output);
    }

    /**
     * @return list<array{unit: string, at: CarbonImmutable, message: string}>
     */
    public function parse(string $output): array
    {
        $kills = [];

        foreach (preg_split('/\R/', trim($output)) ?: [] as $line) {
            $entry = json_decode($line, true);

            if (!is_array($entry) || !is_string($entry['MESSAGE'] ?? null)) {
                continue;
            }

            $unit = is_string($entry['UNIT'] ?? null) ? $entry['UNIT'] : (string) ($entry['_SYSTEMD_UNIT'] ?? '');

            if (preg_match('/^(coresmith-app-[a-z0-9-]+)\.service$/', $unit, $matches) !== 1 || stripos($entry['MESSAGE'], 'oom') === false) {
                continue;
            }

            $kills[] = [
                'unit' => $matches[1],
                'at' => CarbonImmutable::createFromTimestamp(intdiv((int) ($entry['__REALTIME_TIMESTAMP'] ?? 0), 1000000)),
                'message' => $entry['MESSAGE'],
            ];
        }

        return $kills;
    }
}
