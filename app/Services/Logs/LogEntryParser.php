<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Logs;

use App\Models\Application;
use App\Services\Deployment\LogSanitizer;
use App\Services\Server\AccessLogReader;

final class LogEntryParser
{
    public const LINE_LENGTH = 2000;

    public const MORE_LINES = 30;

    public const MORE_LENGTH = 500;

    public const PATH_LENGTH = 300;

    private const TERMINAL_CODE = '/\e(?:\[[0-?]*[ -\/]*[@-~]|\][^\a\e]*(?:\a|\e\\\\)|[@-Z\\\\-_])/';

    private const CONTROL = '/[\x{0}-\x{8}\x{B}-\x{1F}\x{7F}-\x{9F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u';

    private const ISO = '/^.{0,24}?(\d{4})[-\/](\d{2})[-\/](\d{2})[T ](\d{2}):(\d{2}):(\d{2})(?:[.,](\d{1,9}))?(?: ?(Z|[+-]\d{2}:?\d{2}))?/';

    private const COMMON = '/^.{0,48}?(\d{2})\/(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)\/(\d{4}):(\d{2}):(\d{2}):(\d{2})(?: ([+-]\d{4}))?/';

    private const MONTHS = ['Jan' => 1, 'Feb' => 2, 'Mar' => 3, 'Apr' => 4, 'May' => 5, 'Jun' => 6, 'Jul' => 7, 'Aug' => 8, 'Sep' => 9, 'Oct' => 10, 'Nov' => 11, 'Dec' => 12];

    private const LEADING_TIME = '/^\s*\[?\d{4}[-\/]\d{2}[-\/]\d{2}[T ]\d{2}:\d{2}:\d{2}(?:[.,]\d{1,9})?(?: ?(?:Z|[+-]\d{2}:?\d{2}))?\]?\s+(?=\S)/';

    private const CONTINUATION = '/^(?:\s|#\d+ |at |Stack trace|Caused by|Next [A-Z]|\[stacktrace\]|\[previous exception\]|\}|\]|\.\.\. \d+ more)/';

    private const ERROR = '/\.(?:EMERGENCY|ALERT|CRITICAL|ERROR):|\b(?:FATAL|ERROR|CRITICAL|PANIC|EMERG)\b|\bFatal error\b|\bUncaught\b|Traceback \(most recent call last\)|^panic:|\[(?:error|crit|alert|emerg)\]|level=(?:error|fatal)|"level":"(?:error|fatal)"|^[EF], \[|\b[A-Z]\w*(?:Error|Exception): /';

    private const WARNING = '/\.(?:WARNING|NOTICE):|\b(?:WARN|WARNING)\b|\bWarning:|\bDeprecated:|\[(?:warn|warning|notice)\]|level=warn|"level":"warn|^W, \[/';

    public function __construct(private readonly LogSanitizer $sanitizer, private readonly AccessLogReader $access) {}

    /**
     * @return list<array{name: string, arg: string, lines: list<string>}>
     */
    public function sections(string $output, string $boundary): array
    {
        $marker = "=={$boundary}== ";
        $sections = [];
        $current = null;

        foreach (explode("\n", str_replace("\r\n", "\n", $output)) as $line) {
            if (str_starts_with($line, $marker)) {
                if ($current !== null) {
                    $sections[] = $current;
                }

                [$name, $arg] = array_pad(explode(' ', substr($line, strlen($marker)), 2), 2, '');
                $current = ['name' => $name, 'arg' => $arg, 'lines' => []];

                continue;
            }

            if ($current !== null) {
                $current['lines'][] = $line;
            }
        }

        if ($current !== null) {
            $sections[] = $current;
        }

        return $sections;
    }

    /**
     * @param  list<array{name: string, arg: string, lines: list<string>}>  $sections
     * @param  array<string, array{0: string, 1: string|null}>  $units
     * @return list<array<string, mixed>>
     */
    public function entries(array $sections, Application $application, array $units): array
    {
        $offset = 0;
        $entries = [];

        foreach ($sections as $section) {
            if ($section['name'] === 'meta') {
                $offset = self::zone(trim($section['lines'][1] ?? '+0000'));

                continue;
            }

            $found = match ($section['name']) {
                'requests' => $this->requests($section['lines'], $application),
                'process' => $this->file($section['lines'], 'process:' . $section['arg'], $section['arg'], $application, $offset, false),
                'framework' => $this->file($section['lines'], 'framework', null, $application, $offset, true),
                'scheduler' => $this->file($section['lines'], 'scheduler', null, $application, $offset, false),
                'php' => $this->journal($section['lines'], $application, fn(array $record): array => ['php', null]),
                'units' => $this->journal($section['lines'], $application, fn(array $record): array => $units[(string) ($record['UNIT'] ?? '')] ?? ['php', null]),
                default => [],
            };

            array_push($entries, ...$found);
        }

        return $this->identify($entries);
    }

    /**
     * @param  list<string>  $lines
     * @return list<array<string, mixed>>
     */
    private function requests(array $lines, Application $application): array
    {
        $domains = $application->allDomains();
        $entries = [];

        foreach ($this->content($lines) as $line) {
            $request = $this->access->request(json_decode($line, true), $domains, self::PATH_LENGTH);

            if ($request === null || $request['at'] === null) {
                continue;
            }

            $entries[] = [
                'at' => $request['at'],
                'sort' => $request['at'],
                'source' => 'requests',
                'process' => null,
                'level' => $request['status'] >= 500 ? 'error' : ($request['status'] >= 400 ? 'warning' : 'info'),
                'text' => "{$request['method']} {$request['path']} {$request['status']} {$request['ms']}ms",
                'more' => [],
                'request' => $request,
            ];
        }

        return $entries;
    }

    /**
     * @param  list<string>  $lines
     * @return list<array<string, mixed>>
     */
    private function file(array $lines, string $source, ?string $process, Application $application, int $offset, bool $headed): array
    {
        if (preg_match('/^\d+ (\d+)$/', trim($lines[0] ?? ''), $stat) !== 1) {
            return [];
        }

        $entries = [];
        $last = null;

        foreach ($this->content(array_slice($lines, 1)) as $raw) {
            $text = $this->clean($raw, $application);

            if (trim($text) === '') {
                continue;
            }

            $at = $this->time($text, $offset);

            if ($at === null && $entries !== [] && ($headed || preg_match(self::CONTINUATION, $text) === 1)) {
                $more = &$entries[array_key_last($entries)]['more'];

                if (count($more) < self::MORE_LINES) {
                    $more[] = mb_substr($text, 0, self::MORE_LENGTH);
                }

                if ($this->level($text) === 'error') {
                    $entries[array_key_last($entries)]['level'] = 'error';
                }

                unset($more);

                continue;
            }

            $last = $at ?? $last;
            $entries[] = ['at' => $at, 'sort' => $last, 'source' => $source, 'process' => $process, 'level' => $this->level($text), 'text' => $at === null ? $text : (string) preg_replace(self::LEADING_TIME, '', $text), 'more' => [], 'request' => null];
        }

        $first = null;

        foreach ($entries as $entry) {
            $first ??= $entry['sort'];
        }

        foreach ($entries as $index => $entry) {
            $entries[$index]['sort'] = $entry['sort'] ?? $first ?? (float) $stat[1];
        }

        return $entries;
    }

    /**
     * @param  list<string>  $lines
     * @param  callable(array<string, mixed>): array{0: string, 1: string|null}  $origin
     * @return list<array<string, mixed>>
     */
    private function journal(array $lines, Application $application, callable $origin): array
    {
        $entries = [];

        foreach ($this->content($lines) as $line) {
            $record = json_decode($line, true);

            if (!is_array($record) || !isset($record['__REALTIME_TIMESTAMP']) || !is_numeric($record['__REALTIME_TIMESTAMP'])) {
                continue;
            }

            $message = $record['MESSAGE'] ?? '';
            $message = is_array($message) ? pack('C*', ...array_map('intval', $message)) : (string) $message;
            $unit = (string) ($record['UNIT'] ?? '');
            $text = $this->clean($unit === '' ? $message : (string) preg_replace('/^' . preg_quote($unit, '/') . ':\s*/', '', $message), $application);

            if (trim($text) === '') {
                continue;
            }

            [$source, $process] = $origin($record);
            $at = (float) $record['__REALTIME_TIMESTAMP'] / 1_000_000;
            $priority = is_numeric($record['PRIORITY'] ?? null) ? (int) $record['PRIORITY'] : 6;
            $level = $this->unitLevel($text) ?? $this->level($text);
            $level = $priority <= 3 || $level === 'error' ? 'error' : ($priority === 4 || $level === 'warning' ? 'warning' : 'info');

            $entries[] = ['at' => $at, 'sort' => $at, 'source' => $source, 'process' => $process, 'level' => $level, 'text' => $text, 'more' => [], 'request' => null];
        }

        return $entries;
    }

    /**
     * @param  list<string>  $lines
     * @return list<string>
     */
    private function content(array $lines): array
    {
        if ($lines !== [] && end($lines) === '') {
            array_pop($lines);
        }

        return $lines;
    }

    private function clean(string $line, Application $application): string
    {
        $plain = (string) preg_replace(self::CONTROL, '', (string) preg_replace(self::TERMINAL_CODE, '', mb_scrub($line, 'UTF-8')));

        return $this->sanitizer->sanitize(mb_substr($plain, 0, self::LINE_LENGTH), $application);
    }

    private function time(string $line, int $offset): ?float
    {
        if (preg_match(self::ISO, $line, $iso) === 1 && checkdate((int) $iso[2], (int) $iso[3], (int) $iso[1]) && (int) $iso[4] < 24 && (int) $iso[5] < 60 && (int) $iso[6] < 61) {
            $zone = $iso[8] ?? '';
            $fraction = ($iso[7] ?? '') === '' ? 0.0 : (float) ('0.' . $iso[7]);

            return gmmktime((int) $iso[4], (int) $iso[5], (int) $iso[6], (int) $iso[2], (int) $iso[3], (int) $iso[1]) + $fraction - ($zone === '' ? $offset : self::zone($zone));
        }

        if (preg_match(self::COMMON, $line, $common) === 1 && checkdate(self::MONTHS[$common[2]], (int) $common[1], (int) $common[3])) {
            $zone = $common[7] ?? '';

            return (float) (gmmktime((int) $common[4], (int) $common[5], (int) $common[6], self::MONTHS[$common[2]], (int) $common[1], (int) $common[3]) - ($zone === '' ? $offset : self::zone($zone)));
        }

        return null;
    }

    private function level(string $text): string
    {
        return preg_match(self::ERROR, $text) === 1 ? 'error' : (preg_match(self::WARNING, $text) === 1 ? 'warning' : 'info');
    }

    private function unitLevel(string $text): ?string
    {
        return match (true) {
            str_contains($text, 'killed by the OOM killer'), str_starts_with($text, 'Failed with result'), preg_match('/^Main process exited, code=\w+, status=(?!0\/)/', $text) === 1 => 'error',
            str_starts_with($text, 'Scheduled restart job') => 'warning',
            default => null,
        };
    }

    private static function zone(string $zone): int
    {
        if ($zone === 'Z' || preg_match('/^([+-])(\d{2}):?(\d{2})$/', $zone, $parts) !== 1) {
            return 0;
        }

        return ($parts[1] === '-' ? -1 : 1) * ((int) $parts[2] * 3600 + (int) $parts[3] * 60);
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     * @return list<array<string, mixed>>
     */
    private function identify(array $entries): array
    {
        $seen = [];

        foreach ($entries as $index => $entry) {
            $key = $entry['source'] . "\0" . $entry['sort'] . "\0" . $entry['text'];
            $seen[$key] = ($seen[$key] ?? 0) + 1;
            $entries[$index]['id'] = substr(hash('xxh3', $key . "\0" . $seen[$key]), 0, 16);
        }

        return $entries;
    }
}
