<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Server;

use App\Models\Server;
use App\Services\SSH\SSHConnection;
use Illuminate\Support\Carbon;

final class HardwareFacts
{
    public const REFRESH_HOURS = 24;

    private const COMMAND = <<<'SH'
        LC_ALL=C lscpu 2>/dev/null | awk -F: '/^Model name:/ { sub(/^[ \t]+/, "", $2); print "cpu_model=" $2; exit }'
        LC_ALL=C lscpu -p=core,socket 2>/dev/null | grep -v '^#' | sort -u | awk 'END { print "cpu_cores=" NR }'
        awk -F= '$1 == "PRETTY_NAME" { gsub(/"/, "", $2); print "os=" $2 }' /etc/os-release
        dmidecode -t 17 2>/dev/null | awk '$1 == "Size:" && $2 ~ /^[0-9]+$/ { mb += ($3 == "TB" ? $2 * 1048576 : ($3 == "GB" ? $2 * 1024 : $2)) } END { print "installed_mb=" mb + 0 }'
        SH;

    private const LONGEST = 120;

    public function command(): string
    {
        return self::COMMAND;
    }

    /**
     * @return array{cpu_model: ?string, cpu_physical_cores: ?int, os_pretty: ?string, memory_installed_mb: ?int}
     */
    public function read(SSHConnection $connection): array
    {
        return $this->parse($connection->execute(self::COMMAND, sudo: true)->output);
    }

    /**
     * @return array{cpu_model: ?string, cpu_physical_cores: ?int, os_pretty: ?string, memory_installed_mb: ?int}
     */
    public function parse(string $output): array
    {
        $values = [];

        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            if (preg_match('/^(cpu_model|cpu_cores|os|installed_mb)=(.*)$/', trim($line), $matches) === 1) {
                $values[$matches[1]] ??= $matches[2];
            }
        }

        $cores = (int) ($values['cpu_cores'] ?? 0);
        $installed = (int) ($values['installed_mb'] ?? 0);

        return [
            'cpu_model' => $this->text($values['cpu_model'] ?? null),
            'cpu_physical_cores' => $cores > 0 ? $cores : null,
            'os_pretty' => $this->text($values['os'] ?? null),
            'memory_installed_mb' => $installed > 0 ? $installed : null,
        ];
    }

    /**
     * @param  array{cpu_model: ?string, cpu_physical_cores: ?int, os_pretty: ?string, memory_installed_mb: ?int}  $facts
     * @return array<string, int|string>
     */
    public function toMeta(array $facts): array
    {
        return [...array_filter($facts, fn(mixed $value): bool => $value !== null), 'facts_at' => now()->toIso8601String()];
    }

    public function due(Server $server): bool
    {
        $at = ((array) $server->meta)['facts_at'] ?? null;

        return !is_string($at) || Carbon::parse($at)->lte(now()->subHours(self::REFRESH_HOURS));
    }

    private function text(?string $value): ?string
    {
        $clean = trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[^\P{C}\t ]/u', '', (string) $value)));

        return $clean === '' ? null : mb_substr($clean, 0, self::LONGEST);
    }
}
