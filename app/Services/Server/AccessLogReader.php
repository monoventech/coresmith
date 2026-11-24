<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Server;

use App\Models\Application;
use App\Services\SSH\SSHConnection;
use Carbon\CarbonImmutable;

final class AccessLogReader
{
    public const LINES = 20;

    public const SCAN = 2000;

    public const PATH_LENGTH = 120;

    public const DETAIL_LENGTH = 300;

    /**
     * @return list<array{time: string, text: string}>
     */
    public function recent(SSHConnection $connection, Application $application): array
    {
        $log = escapeshellarg(MetricsCollector::ACCESS_LOG);
        $output = $connection->execute("test -f {$log} && tail -n " . self::SCAN . " {$log} || true", sudo: true)->output;

        return $this->parse($output, $application->allDomains());
    }

    /**
     * @param  list<string>  $domains
     * @return list<array{time: string, text: string}>
     */
    public function parse(string $output, array $domains): array
    {
        $lines = [];

        foreach (preg_split('/\r\n|\n|\r/', $output) ?: [] as $raw) {
            $request = $this->request(json_decode($raw, true), $domains);

            if ($request === null) {
                continue;
            }

            $time = $request['at'] === null ? '--:--:--' : CarbonImmutable::createFromTimestamp($request['at'])->format('H:i:s');

            $lines[] = ['time' => $time, 'text' => "{$request['method']} {$request['path']} {$request['status']} {$request['ms']}ms"];
        }

        return array_slice($lines, -self::LINES);
    }

    /**
     * @param  list<string>  $domains
     * @return array{at: float|null, method: string, path: string, status: int, ms: int, bytes: int, ip: string|null, agent: string|null, referer: string|null, host: string, proto: string|null}|null
     */
    public function request(mixed $entry, array $domains, int $pathLength = self::PATH_LENGTH): ?array
    {
        if (!is_array($entry) || !isset($entry['request']) || !is_array($entry['request'])) {
            return null;
        }

        $request = $entry['request'];
        $host = strtolower((string) ($request['host'] ?? ''));

        if (!in_array($host, $domains, true)) {
            return null;
        }

        $headers = is_array($request['headers'] ?? null) ? $request['headers'] : [];
        $referer = self::printable(self::header($headers, 'Referer'), self::DETAIL_LENGTH);

        return [
            'at' => isset($entry['ts']) && is_numeric($entry['ts']) ? (float) $entry['ts'] : null,
            'method' => preg_replace('/[^A-Z]/', '', (string) ($request['method'] ?? '')) ?: 'GET',
            'path' => self::mask(self::printable((string) ($request['uri'] ?? '/'), $pathLength) ?: '/'),
            'status' => (int) ($entry['status'] ?? 0),
            'ms' => (int) round(((float) ($entry['duration'] ?? 0)) * 1000),
            'bytes' => (int) ($entry['size'] ?? 0),
            'ip' => self::printable((string) ($request['client_ip'] ?? $request['remote_ip'] ?? ''), 64) ?: null,
            'agent' => self::printable(self::header($headers, 'User-Agent'), self::DETAIL_LENGTH) ?: null,
            'referer' => $referer === '' ? null : self::mask($referer),
            'host' => $host,
            'proto' => self::printable((string) ($request['proto'] ?? ''), 16) ?: null,
        ];
    }

    public static function mask(string $uri): string
    {
        return (string) preg_replace('/([?&][^=&]*(token|key|secret|signature|password|passwd|auth|session|credential)[^=&]*=)[^&]*/i', '$1•••', $uri);
    }

    private static function printable(string $value, int $length): string
    {
        return mb_substr((string) preg_replace('/[^\x20-\x7E]/', '', $value), 0, $length);
    }

    /**
     * @param  array<mixed>  $headers
     */
    private static function header(array $headers, string $name): string
    {
        $value = $headers[$name] ?? null;

        return is_array($value) ? (string) ($value[0] ?? '') : (is_string($value) ? $value : '');
    }
}
