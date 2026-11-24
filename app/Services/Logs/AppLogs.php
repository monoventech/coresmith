<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Logs;

use App\Models\Application;
use App\Services\Deployment\AppShell;
use App\Services\Deployment\ReleasePaths;
use App\Services\Process\FpmPoolBuilder;
use App\Services\Process\ProcessManager;
use App\Services\Server\MetricsCollector;
use App\Services\SSH\SSHConnection;

final class AppLogs
{
    public const KEEP = 1000;

    public const READ_SECONDS = 10;

    public const JOURNAL_ENTRIES = 1000;

    public const UNIT_EVENTS = 300;

    public function __construct(
        private readonly ProcessManager $processes,
        private readonly FpmPoolBuilder $fpm,
        private readonly AppShell $shell,
        private readonly LogEntryParser $parser,
    ) {}

    /**
     * @return array{entries: list<array<string, mixed>>, stats: array<string, mixed>, capped: bool}
     */
    public function read(SSHConnection $connection, Application $application, LogQuery $query): array
    {
        $boundary = bin2hex(random_bytes(8));
        $output = $connection->execute($this->rootScript($application, $query, $boundary), sudo: true, timeout: self::READ_SECONDS + 5)->output;
        $own = $this->appScript($application, $query, $boundary);

        if ($own !== null) {
            $output .= "\n" . $connection->execute($own, sudo: true, timeout: self::READ_SECONDS + 5)->output;
        }

        $entries = $this->parser->entries($this->parser->sections($output, $boundary), $application, $this->unitSources($application));
        $since = $query->since(now()->getTimestamp());
        $until = $query->until();

        $entries = array_values(array_filter($entries, fn(array $entry): bool => ($since === null || $entry['sort'] >= $since) && ($until === null || $entry['sort'] <= $until + 1)));
        $requests = array_values(array_filter($entries, fn(array $entry): bool => $entry['request'] !== null));
        $entries = self::ordered($entries);

        return [
            'entries' => array_slice($entries, -self::KEEP),
            'stats' => LogStats::of(array_column($requests, 'request'), $since === null ? null : (($until ?? now()->getTimestamp()) - $since)),
            'capped' => count($entries) > self::KEEP,
        ];
    }

    public function rootScript(Application $application, LogQuery $query, string $boundary): string
    {
        $paths = new ReleasePaths($application);
        $search = self::search($query);
        $range = self::range($query);
        $domains = $application->allDomains();
        $lines = [self::mark($boundary, 'meta') . '; date +%s; date +%z'];

        if ($domains !== []) {
            $hosts = ' | grep -F -i' . implode('', array_map(fn(string $domain): string => ' -e ' . escapeshellarg('"host":"' . $domain . '"'), $domains));
            $lines[] = self::mark($boundary, 'requests') . '; ' . self::file(MetricsCollector::ACCESS_LOG, $query->bytes(), $hosts . $search);
        }

        foreach ($this->unitSources($application) as [$source, $process]) {
            if ($process !== null) {
                $lines[] = self::mark($boundary, 'process', $process) . '; ' . self::file($paths->processLog($process), $query->bytes(), $search);
            }
        }

        if ($application->type->language()->usesFpm()) {
            $slug = $application->slug;
            $lines[] = self::mark($boundary, 'php') . '; journalctl -o json --no-pager -n ' . self::JOURNAL_ENTRIES . ' -t ' . escapeshellarg("coresmith-app-{$slug}-php") . ' -t ' . escapeshellarg("coresmith-app-{$slug}-fpm") . $range . ' 2>/dev/null' . $search;
        }

        $units = array_keys($this->unitSources($application));

        if ($units !== []) {
            $lines[] = self::mark($boundary, 'units') . '; journalctl -o json --no-pager -n ' . self::UNIT_EVENTS . ' _PID=1 ' . implode(' ', array_map(fn(string $unit): string => escapeshellarg('UNIT=' . $unit), $units)) . $range . ' 2>/dev/null' . $search;
        }

        return implode("\n", $lines);
    }

    public function appScript(Application $application, LogQuery $query, string $boundary): ?string
    {
        if ($application->current_release === null) {
            return null;
        }

        $search = self::search($query);
        $bytes = $query->bytes();
        $lines = [self::mark($boundary, 'scheduler') . '; ' . self::file('logs/scheduler.log', $bytes, $search)];

        if ($application->framework === 'laravel') {
            $lines[] = self::mark($boundary, 'framework') . '; f=$(ls -t storage/logs/laravel*.log 2>/dev/null | head -n 1); if [ -n "$f" ]; then ' . self::tail('"$f"', $bytes, $search) . '; fi';
        }

        return $this->shell->run($application, implode("\n", $lines), (new ReleasePaths($application))->shared(), [], self::READ_SECONDS);
    }

    /**
     * @return array<string, array{0: string, 1: string|null}>
     */
    public function unitSources(Application $application): array
    {
        $sources = [];
        $fpm = $application->type->language()->usesFpm() ? $this->fpm->unitName($application) : null;

        foreach ($this->processes->unitNames($application) as $unit) {
            if ($unit === $fpm) {
                $sources[$unit . '.service'] = ['php', null];

                continue;
            }

            $process = $this->processes->process($application, $unit);
            $sources[$unit . '.service'] = ['process:' . $process, $process];
        }

        return $sources;
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     * @return list<array<string, mixed>>
     */
    public static function ordered(array $entries): array
    {
        $keyed = array_map(fn(array $entry, int $index): array => [$entry, $index], $entries, array_keys($entries));

        usort($keyed, fn(array $a, array $b): int => [$a[0]['sort'], $a[1]] <=> [$b[0]['sort'], $b[1]]);

        return array_map(fn(array $pair): array => $pair[0], $keyed);
    }

    private static function mark(string $boundary, string $name, string $argument = ''): string
    {
        return 'echo ' . escapeshellarg("=={$boundary}== {$name}" . ($argument === '' ? '' : " {$argument}"));
    }

    private static function file(string $path, int $bytes, string $filter): string
    {
        $file = escapeshellarg($path);

        return "if [ -f {$file} ]; then " . self::tail($file, $bytes, $filter) . '; fi';
    }

    private static function tail(string $file, int $bytes, string $filter): string
    {
        return "stat -c '%s %Y' {$file}; if [ \"\$(stat -c %s {$file})\" -gt {$bytes} ]; then tail -c {$bytes} {$file} | tail -n +2; else cat {$file}; fi{$filter}";
    }

    private static function search(LogQuery $query): string
    {
        return $query->search === '' ? '' : ' | grep -F -i -e ' . escapeshellarg($query->search);
    }

    private static function range(LogQuery $query): string
    {
        $since = $query->since(now()->getTimestamp());
        $until = $query->until();

        return ($since === null ? '' : ' --since=@' . $since) . ($until === null ? '' : ' --until=@' . $until);
    }
}
