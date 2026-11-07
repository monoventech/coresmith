<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Updates;

final class ReleaseFeed
{
    private const HEADING = '/^## v(\S+) \((\d{4}-\d{2}-\d{2})\)\s*$/m';

    public function __construct(private readonly ?string $changelogPath = null) {}

    public function document(): FeedDocument
    {
        $version = Version::current();
        $channel = config('coresmith.release_feed.channel', 'stable');

        if ($channel === 'stable' && $version->isPrerelease()) {
            throw new InvalidFeed('This deployment runs a pre-release, so it cannot serve the stable feed.');
        }

        $entry = self::changelogEntry($this->changelog(), $version);
        $minimum = trim((string) config('coresmith.release_feed.minimum'));

        return FeedDocument::fromArray([
            'schema' => FeedDocument::SCHEMA,
            'latest' => (string) $version,
            'minimum' => $minimum === '' ? null : $minimum,
            'withdrawn' => array_values(array_filter(
                array_map('trim', explode(',', (string) config('coresmith.release_feed.withdrawn', ''))),
                fn(string $entry): bool => $entry !== '',
            )),
            'changelog_url' => str_replace('{version}', (string) $version, (string) config('coresmith.release_feed.changelog_url', '')),
            'summary' => $entry['summary'] ?? null,
            'released_at' => $entry['released_at'] ?? null,
            'channel' => $channel,
        ]);
    }

    /**
     * @return array{summary: string, released_at: ?string}|null
     */
    public static function changelogEntry(string $markdown, Version $version): ?array
    {
        preg_match_all(self::HEADING, $markdown, $headings, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        foreach ($headings as $heading) {
            if (Version::tryParse($heading[1][0])?->equals($version) !== true) {
                continue;
            }

            $start = $heading[0][1] + strlen($heading[0][0]);
            $end = preg_match('/^## /m', $markdown, $next, PREG_OFFSET_CAPTURE, $start) === 1 ? $next[0][1] : strlen($markdown);

            return [
                'summary' => trim(str_replace("\r\n", "\n", substr($markdown, $start, $end - $start))),
                'released_at' => $heading[2][0],
            ];
        }

        return null;
    }

    private function changelog(): string
    {
        $path = $this->changelogPath ?? base_path('CHANGELOG.md');

        return is_file($path) ? (string) file_get_contents($path) : '';
    }
}
