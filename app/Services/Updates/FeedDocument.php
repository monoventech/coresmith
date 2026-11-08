<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Updates;

use Carbon\CarbonImmutable;
use Throwable;

final class FeedDocument
{
    public const SCHEMA = 1;

    public const MAX_SUMMARY = 1000;

    public const MAX_WITHDRAWN = 200;

    public const CHANNELS = ['stable', 'beta'];

    private const DATE = '/^\d{4}-\d{2}-\d{2}$/D';

    private const DATE_TIME = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2}(?:\.\d{1,6})?)?(?:Z|[+-]\d{2}:\d{2})$/D';

    /**
     * @param  list<Version>  $withdrawn
     */
    private function __construct(
        public readonly Version $latest,
        public readonly ?Version $minimum,
        public readonly array $withdrawn,
        public readonly ?string $changelogUrl,
        public readonly ?string $summary,
        public readonly ?CarbonImmutable $releasedAt,
        public readonly string $channel,
        private readonly bool $releasedAtHasTime,
    ) {}

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        if (($data['schema'] ?? self::SCHEMA) !== self::SCHEMA) {
            throw new InvalidFeed('schema is newer than this version of Coresmith understands.');
        }

        $channel = $data['channel'] ?? 'stable';

        if (!in_array($channel, self::CHANNELS, true)) {
            throw new InvalidFeed('channel is neither stable nor beta.');
        }

        if (($data['latest'] ?? null) === null) {
            throw new InvalidFeed('latest is missing.');
        }

        $latest = Version::tryParse($data['latest']) ?? throw new InvalidFeed('latest is not a version like 1.2.3.');

        if ($channel === 'stable' && $latest->isPrerelease()) {
            throw new InvalidFeed('latest is a pre-release, which the stable channel never offers.');
        }

        $minimum = ($data['minimum'] ?? null) === null
            ? null
            : Version::tryParse($data['minimum']) ?? throw new InvalidFeed('minimum is not a version like 1.2.3.');

        [$releasedAt, $hasTime] = self::releasedAt($data['released_at'] ?? null);

        return new self(
            $latest,
            $minimum,
            self::withdrawn($data['withdrawn'] ?? null),
            self::changelogUrl($data['changelog_url'] ?? null),
            self::summary($data['summary'] ?? null),
            $releasedAt,
            $channel,
            $hasTime,
        );
    }

    public function isWithdrawn(Version $version): bool
    {
        foreach ($this->withdrawn as $withdrawn) {
            if ($withdrawn->equals($version)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{schema: int, latest: string, minimum: ?string, withdrawn: list<string>, changelog_url: ?string, summary: ?string, released_at: ?string, channel: string}
     */
    public function toArray(): array
    {
        return [
            'schema' => self::SCHEMA,
            'latest' => (string) $this->latest,
            'minimum' => $this->minimum === null ? null : (string) $this->minimum,
            'withdrawn' => array_map(fn(Version $version): string => (string) $version, $this->withdrawn),
            'changelog_url' => $this->changelogUrl,
            'summary' => $this->summary,
            'released_at' => match (true) {
                $this->releasedAt === null => null,
                $this->releasedAtHasTime => $this->releasedAt->toIso8601String(),
                default => $this->releasedAt->format('Y-m-d'),
            },
            'channel' => $this->channel,
        ];
    }

    /**
     * @return list<Version>
     */
    private static function withdrawn(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        if (!is_array($value) || !array_is_list($value)) {
            throw new InvalidFeed('withdrawn is not a list of versions.');
        }

        if (count($value) > self::MAX_WITHDRAWN) {
            throw new InvalidFeed('withdrawn lists more than ' . self::MAX_WITHDRAWN . ' versions.');
        }

        return array_map(
            fn(mixed $entry): Version => Version::tryParse($entry) ?? throw new InvalidFeed('withdrawn holds something that is not a version like 1.2.3.'),
            $value,
        );
    }

    private static function changelogUrl(mixed $value): ?string
    {
        return is_string($value) && str_starts_with($value, 'https://') ? $value : null;
    }

    private static function summary(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $text = (string) preg_replace('/[^\P{C}\n]/u', '', mb_scrub($value, 'UTF-8'));
        $text = mb_substr(trim($text), 0, self::MAX_SUMMARY);

        return $text === '' ? null : $text;
    }

    /**
     * @return array{0: ?CarbonImmutable, 1: bool}
     */
    private static function releasedAt(mixed $value): array
    {
        if (!is_string($value)) {
            return [null, false];
        }

        try {
            if (preg_match(self::DATE, $value) === 1) {
                $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, 'UTC');

                return $date !== null && $date->format('Y-m-d') === $value ? [$date, false] : [null, false];
            }

            if (preg_match(self::DATE_TIME, $value) === 1) {
                return [CarbonImmutable::parse($value), true];
            }
        } catch (Throwable) {
            return [null, false];
        }

        return [null, false];
    }
}
