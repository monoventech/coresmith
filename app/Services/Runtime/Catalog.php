<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Runtime;

final class Catalog
{
    public const RUNTIMES = ['php', 'nodejs', 'bun', 'deno', 'ruby'];

    public function label(string $runtime): string
    {
        return (string) config("coresmith.runtimes.{$runtime}.label", $runtime);
    }

    /**
     * @return list<array{line: string, latest: string, active_until: ?string, eol_at: ?string}>
     */
    public function lines(string $runtime): array
    {
        $lines = array_values(array_filter((array) config("coresmith.runtimes.{$runtime}.versions", []), 'is_array'));

        usort($lines, fn(array $a, array $b): int => version_compare((string) $b['line'], (string) $a['line']));

        return array_map(fn(array $line): array => [
            'line' => (string) $line['line'],
            'latest' => (string) $line['latest'],
            'active_until' => isset($line['active_until']) ? (string) $line['active_until'] : null,
            'eol_at' => isset($line['eol_at']) ? (string) $line['eol_at'] : null,
        ], $lines);
    }

    /**
     * @return array{line: string, latest: string, active_until: ?string, eol_at: ?string}|null
     */
    public function line(string $runtime, string $line): ?array
    {
        foreach ($this->lines($runtime) as $candidate) {
            if ($candidate['line'] === $line) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function versions(string $runtime): array
    {
        return array_column($this->lines($runtime), 'latest');
    }

    public function recommendedLine(string $runtime): string
    {
        return (string) config("coresmith.runtimes.{$runtime}.recommended", '');
    }

    public function newestLine(string $runtime): ?string
    {
        return $this->lines($runtime)[0]['line'] ?? null;
    }

    public function oldestLine(string $runtime): ?string
    {
        $lines = $this->lines($runtime);

        return $lines === [] ? null : $lines[count($lines) - 1]['line'];
    }

    public function lineOf(string $runtime, string $version): ?string
    {
        if (preg_match('/^v?(\d+)(?:\.(\d+))?/', $version, $matches) !== 1) {
            return null;
        }

        return config("coresmith.runtimes.{$runtime}.line") === 'minor'
            ? $matches[1] . '.' . ($matches[2] ?? '0')
            : $matches[1];
    }

    public function range(string $runtime): string
    {
        $oldest = (string) $this->oldestLine($runtime);
        $newest = (string) $this->newestLine($runtime);

        return $oldest === $newest ? $newest : $oldest . '–' . $newest;
    }
}
