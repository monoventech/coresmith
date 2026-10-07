<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Runtime;

use Carbon\CarbonImmutable;

final readonly class VersionStatus
{
    public const ACTIVE = 'active';

    public const MAINTENANCE = 'maintenance';

    public const EOL = 'eol';

    public const PRERELEASE = 'pre-release';

    public const UNKNOWN = 'unknown';

    public function __construct(
        public string $state,
        public ?CarbonImmutable $activeUntil = null,
        public ?CarbonImmutable $eolAt = null,
    ) {}

    public function label(): string
    {
        return match ($this->state) {
            self::ACTIVE => $this->activeUntil === null ? 'Active' : 'Active until ' . $this->activeUntil->format('M Y'),
            self::MAINTENANCE => $this->eolAt === null ? 'Security fixes only' : 'Security fixes until ' . $this->eolAt->format('M Y'),
            self::EOL => 'End of life',
            self::PRERELEASE => 'Pre-release',
            default => '',
        };
    }

    public function tone(): string
    {
        return match ($this->state) {
            self::ACTIVE => 'success',
            self::MAINTENANCE => 'warning',
            self::EOL => 'destructive',
            default => 'default',
        };
    }

    public function isEol(): bool
    {
        return $this->state === self::EOL;
    }

    /**
     * @return array{state: string, label: string, tone: string}
     */
    public function toArray(): array
    {
        return ['state' => $this->state, 'label' => $this->label(), 'tone' => $this->tone()];
    }
}
