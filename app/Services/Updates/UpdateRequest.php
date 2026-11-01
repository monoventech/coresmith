<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Updates;

use Carbon\CarbonImmutable;
use Throwable;

final class UpdateRequest
{
    public const TRIGGERS = ['panel', 'auto', 'recover'];

    public function __construct(
        public readonly ?Version $to,
        public readonly string $trigger,
        public readonly ?int $requestedBy,
        public readonly CarbonImmutable $requestedAt,
    ) {}

    /**
     * @return array{schema: int, to: ?string, trigger: string, requested_by: ?int, requested_at: string}
     */
    public function toArray(): array
    {
        return [
            'schema' => 1,
            'to' => $this->to === null ? null : (string) $this->to,
            'trigger' => $this->trigger,
            'requested_by' => $this->requestedBy,
            'requested_at' => $this->requestedAt->toIso8601String(),
        ];
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): ?self
    {
        $trigger = $data['trigger'] ?? null;
        $to = Version::tryParse($data['to'] ?? null);
        $requestedBy = $data['requested_by'] ?? null;

        if (!in_array($trigger, self::TRIGGERS, true) || ($to === null) !== ($trigger === 'recover') || ($requestedBy !== null && !is_int($requestedBy))) {
            return null;
        }

        try {
            $requestedAt = CarbonImmutable::parse((string) ($data['requested_at'] ?? ''));
        } catch (Throwable) {
            return null;
        }

        return new self($to, $trigger, $requestedBy, $requestedAt);
    }
}
