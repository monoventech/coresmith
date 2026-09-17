<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Server\Audit;

use App\Models\Server;
use Carbon\CarbonImmutable;

final class ServerAudit
{
    public const FRESH_MINUTES = 60;

    public const RUNNING_MINUTES = 5;

    public static function start(Server $server): void
    {
        $server->forceFill(['audit' => ['state' => 'running', 'started_at' => now()->toIso8601String()]])->save();
    }

    public static function finish(Server $server, AuditReport $report): void
    {
        $server->forceFill(['audit' => ['state' => 'done', 'ran_at' => now()->toIso8601String(), 'findings' => $report->toArray()]])->save();
    }

    public static function fail(Server $server, string $error): void
    {
        $server->forceFill(['audit' => ['state' => 'failed', 'ran_at' => now()->toIso8601String(), 'error' => $error]])->save();
    }

    public static function isRunning(Server $server): bool
    {
        $audit = (array) $server->audit;

        return ($audit['state'] ?? null) === 'running'
            && is_string($audit['started_at'] ?? null)
            && CarbonImmutable::parse($audit['started_at'])->gte(now()->subMinutes(self::RUNNING_MINUTES));
    }

    public static function isFresh(Server $server): bool
    {
        $audit = (array) $server->audit;

        return ($audit['state'] ?? null) === 'done'
            && is_string($audit['ran_at'] ?? null)
            && CarbonImmutable::parse($audit['ran_at'])->gte(now()->subMinutes(self::FRESH_MINUTES));
    }

    public static function blocks(Server $server): bool
    {
        return self::report($server)?->hasBlockers() ?? false;
    }

    /**
     * @return list<string>
     */
    public static function fixes(Server $server): array
    {
        return self::report($server)?->fixes() ?? [];
    }

    public static function report(Server $server): ?AuditReport
    {
        $audit = (array) $server->audit;

        if (($audit['state'] ?? null) !== 'done') {
            return null;
        }

        return new AuditReport(array_values(array_map(
            fn(array $finding): AuditFinding => AuditFinding::fromArray($finding),
            array_filter((array) ($audit['findings'] ?? []), 'is_array'),
        )));
    }
}
