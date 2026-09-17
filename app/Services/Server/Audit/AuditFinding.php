<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Server\Audit;

use App\Enums\AuditSeverity;

final readonly class AuditFinding
{
    public function __construct(
        public string $key,
        public AuditSeverity $severity,
        public string $title,
        public string $detail,
        public ?string $fix = null,
    ) {}

    /**
     * @param  array<string, mixed>  $finding
     */
    public static function fromArray(array $finding): self
    {
        return new self(
            (string) ($finding['key'] ?? ''),
            AuditSeverity::tryFrom((string) ($finding['severity'] ?? '')) ?? AuditSeverity::Info,
            (string) ($finding['title'] ?? ''),
            (string) ($finding['detail'] ?? ''),
            isset($finding['fix']) && is_string($finding['fix']) ? $finding['fix'] : null,
        );
    }

    /**
     * @return array{key: string, severity: string, title: string, detail: string, fix: string|null}
     */
    public function toArray(): array
    {
        return ['key' => $this->key, 'severity' => $this->severity->value, 'title' => $this->title, 'detail' => $this->detail, 'fix' => $this->fix];
    }
}
