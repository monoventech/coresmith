<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Logs;

use App\Services\Server\MetricsCollector;
use App\Services\SSH\SSHConnection;

final class ServerLogs
{
    public const FIREWALL_LOG = '/var/log/fail2ban.log';

    public const JOURNAL_ENTRIES = 500;

    public function __construct(private readonly LogEntryParser $parser) {}

    /**
     * @return array{entries: list<array<string, mixed>>, stats: array<string, mixed>, capped: bool, cut: list<array{source: string, level: string}>}
     */
    public function read(SSHConnection $connection, LogQuery $query): array
    {
        $boundary = bin2hex(random_bytes(8));
        $output = $connection->execute($this->script($query, $boundary), sudo: true, timeout: AppLogs::READ_SECONDS + 5)->output;
        $entries = $this->parser->entries($this->parser->sections($output, $boundary), null, [], self::keyFingerprint($connection->server()->ssh_public_key));
        $since = $query->since(now()->getTimestamp());
        $until = $query->until();

        $entries = array_values(array_filter($entries, fn(array $entry): bool => ($since === null || $entry['sort'] >= $since) && ($until === null || $entry['sort'] <= $until + 1)));
        $requests = array_values(array_filter($entries, fn(array $entry): bool => $entry['request'] !== null));
        $kept = AppLogs::newest(AppLogs::ordered($entries));

        return [
            'entries' => $kept['entries'],
            'stats' => LogStats::of(array_column($requests, 'request'), $since === null ? null : (($until ?? now()->getTimestamp()) - $since)),
            'capped' => $kept['cut'] !== [],
            'cut' => $kept['cut'],
        ];
    }

    public function script(LogQuery $query, string $boundary): string
    {
        $mark = fn(string $name): string => 'echo ' . escapeshellarg("=={$boundary}== {$name}");
        $search = $query->search === '' ? '' : ' | grep -F -i -e ' . escapeshellarg($query->search);
        $since = $query->since(now()->getTimestamp());
        $until = $query->until();
        $range = ($since === null ? '' : ' --since=@' . $since) . ($until === null ? '' : ' --until=@' . $until);
        $journal = fn(string $matches): string => 'journalctl -o json --no-pager -n ' . self::JOURNAL_ENTRIES . ' ' . $matches . $range . ' 2>/dev/null' . $search;

        return implode("\n", [
            $mark('meta') . '; date +%s; date +%z',
            $mark('requests') . '; ' . self::file(MetricsCollector::ACCESS_LOG, $query->bytes(), $search),
            $mark('web') . '; ' . $journal('-u caddy'),
            $mark('signins') . '; ' . $journal('-u ssh -u sshd'),
            $mark('firewall') . '; ' . self::file(self::FIREWALL_LOG, $query->bytes(), $search),
            $mark('apps') . '; ' . $journal(escapeshellarg('--unit=coresmith-app-*')),
            $mark('system') . '; journalctl -o json --no-pager -r -p warning' . $range . ' 2>/dev/null | grep -v -F ' . escapeshellarg('"MESSAGE":"[UFW ') . $search . ' | head -n ' . self::JOURNAL_ENTRIES . ' | tac',
        ]);
    }

    public static function keyFingerprint(?string $publicKey): ?string
    {
        $blob = base64_decode(explode(' ', trim((string) $publicKey))[1] ?? '', true);

        return $blob === false || $blob === '' ? null : 'SHA256:' . rtrim(base64_encode(hash('sha256', $blob, true)), '=');
    }

    private static function file(string $path, int $bytes, string $filter): string
    {
        $file = escapeshellarg($path);

        return "if [ -f {$file} ]; then stat -c '%s %Y' {$file}; if [ \"\$(stat -c %s {$file})\" -gt {$bytes} ]; then tail -c {$bytes} {$file} | tail -n +2; else cat {$file}; fi{$filter}; fi";
    }
}
