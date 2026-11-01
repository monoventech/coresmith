<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Updates;

use Carbon\CarbonImmutable;
use Throwable;

final class UpdateCheck
{
    public function __construct(
        public readonly CarbonImmutable $checkedAt,
        public readonly string $url,
        public readonly ?FeedDocument $feed,
        public readonly ?string $error,
        public readonly ?CarbonImmutable $failedAt,
    ) {}

    /**
     * @return array{checked_at: string, url: string, feed: ?array<string, mixed>, error: ?string, failed_at: ?string}
     */
    public function toArray(): array
    {
        return [
            'checked_at' => $this->checkedAt->toIso8601String(),
            'url' => $this->url,
            'feed' => $this->feed?->toArray(),
            'error' => $this->error,
            'failed_at' => $this->failedAt?->toIso8601String(),
        ];
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): ?self
    {
        try {
            $checkedAt = CarbonImmutable::parse((string) ($data['checked_at'] ?? ''));
            $feed = is_array($data['feed'] ?? null) ? FeedDocument::fromArray($data['feed']) : null;
            $failedAt = is_string($data['failed_at'] ?? null) ? CarbonImmutable::parse($data['failed_at']) : null;
        } catch (Throwable) {
            return null;
        }

        return new self(
            $checkedAt,
            is_string($data['url'] ?? null) ? $data['url'] : '',
            $feed,
            is_string($data['error'] ?? null) ? $data['error'] : null,
            $failedAt,
        );
    }

    public function ok(): bool
    {
        return $this->error === null;
    }

    public function available(Version $current): ?Version
    {
        if ($this->feed === null || !$this->feed->latest->isNewerThan($current) || $this->feed->isWithdrawn($this->feed->latest)) {
            return null;
        }

        return $this->feed->latest;
    }

    public function autoTarget(Version $current): ?Version
    {
        $available = $this->available($current);

        return $available !== null && $available->isPatchUpdateOf($current) ? $available : null;
    }

    public function currentWithdrawn(Version $current): bool
    {
        return $this->feed?->isWithdrawn($current) ?? false;
    }

    public function unsupported(Version $current): bool
    {
        return $this->feed?->minimum !== null && $this->feed->minimum->isNewerThan($current);
    }

    public function channel(): string
    {
        return $this->feed->channel ?? 'stable';
    }
}
