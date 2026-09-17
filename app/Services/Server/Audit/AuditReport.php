<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Server\Audit;

use App\Enums\AuditSeverity;

final readonly class AuditReport
{
    /** @var list<AuditFinding> */
    public array $findings;

    /**
     * @param  list<AuditFinding>  $findings
     */
    public function __construct(array $findings)
    {
        usort($findings, fn(AuditFinding $a, AuditFinding $b): int => $a->severity->rank() <=> $b->severity->rank());

        $this->findings = $findings;
    }

    public function hasBlockers(): bool
    {
        foreach ($this->findings as $finding) {
            if ($finding->severity === AuditSeverity::Blocker) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public function fixes(): array
    {
        return array_values(array_unique(array_filter(array_map(fn(AuditFinding $finding): ?string => $finding->fix, $this->findings))));
    }

    /**
     * @return list<array{key: string, severity: string, title: string, detail: string, fix: string|null}>
     */
    public function toArray(): array
    {
        return array_map(fn(AuditFinding $finding): array => $finding->toArray(), $this->findings);
    }
}
