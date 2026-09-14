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

    /**
     * @return list<array{time: string, text: string}>
     */
    public function recent(SSHConnection $connection, Application $application): array
    {
        $log = escapeshellarg(MetricsCollector::ACCESS_LOG);
        $output = $connection->execute("test -f {$log} && tail -n " . self::SCAN . " {$log} || true")->output;

        return $this->parse($output, $application->allDomains());
    }

    /**
     * @param  list<string>  $domains
     * @return list<array{time: string, text: string}>
     */
    public function parse(string $output, array $domains): array
    {
        $lines = [];

        foreach (preg_split('/\R/', $output) ?: [] as $raw) {
            $entry = json_decode($raw, true);

            if (!is_array($entry) || !isset($entry['request']) || !is_array($entry['request'])) {
                continue;
            }

            $host = strtolower((string) ($entry['request']['host'] ?? ''));

            if (!in_array($host, $domains, true)) {
                continue;
            }

            $method = preg_replace('/[^A-Z]/', '', (string) ($entry['request']['method'] ?? '')) ?: 'GET';
            $uri = mb_substr(preg_replace('/[^\x20-\x7E]/', '', (string) ($entry['request']['uri'] ?? '/')) ?: '/', 0, 120);
            $uri = (string) preg_replace('/([?&][^=&]*(token|key|secret|signature|password|passwd|auth|session|credential)[^=&]*=)[^&]*/i', '$1•••', $uri);
            $status = (int) ($entry['status'] ?? 0);
            $milliseconds = (int) round(((float) ($entry['duration'] ?? 0)) * 1000);
            $time = isset($entry['ts']) && is_numeric($entry['ts']) ? CarbonImmutable::createFromTimestamp((float) $entry['ts'])->format('H:i:s') : '--:--:--';

            $lines[] = ['time' => $time, 'text' => "{$method} {$uri} {$status} {$milliseconds}ms"];
        }

        return array_slice($lines, -self::LINES);
    }
}
