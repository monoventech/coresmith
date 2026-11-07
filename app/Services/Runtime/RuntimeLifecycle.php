<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Runtime;

use Carbon\CarbonImmutable;

final class RuntimeLifecycle
{
    public function __construct(private readonly Catalog $catalog) {}

    public function status(string $runtime, string $version): VersionStatus
    {
        if (preg_match('/(?:-|\d)(?:alpha|beta|rc|pre|dev)/i', $version) === 1) {
            return new VersionStatus(VersionStatus::PRERELEASE);
        }

        $line = $this->catalog->lineOf($runtime, $version);
        $data = $line === null ? null : $this->catalog->line($runtime, $line);

        if ($data === null || ($data['active_until'] === null && $data['eol_at'] === null)) {
            return new VersionStatus(VersionStatus::UNKNOWN);
        }

        $activeUntil = $data['active_until'] === null ? null : CarbonImmutable::parse($data['active_until'])->endOfDay();
        $eolAt = $data['eol_at'] === null ? null : CarbonImmutable::parse($data['eol_at'])->endOfDay();

        return match (true) {
            $eolAt !== null && now()->greaterThan($eolAt) => new VersionStatus(VersionStatus::EOL, $activeUntil, $eolAt),
            $activeUntil !== null && now()->greaterThan($activeUntil) => new VersionStatus(VersionStatus::MAINTENANCE, $activeUntil, $eolAt),
            default => new VersionStatus(VersionStatus::ACTIVE, $activeUntil, $eolAt),
        };
    }

    /**
     * @return list<array{line: string, version: string, status: array{state: string, label: string, tone: string}}>
     */
    public function options(string $runtime): array
    {
        return array_map(fn(array $line): array => [
            'line' => $line['line'],
            'version' => $line['latest'],
            'status' => $this->status($runtime, $line['latest'])->toArray(),
        ], $this->catalog->lines($runtime));
    }
}
