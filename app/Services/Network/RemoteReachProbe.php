<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Network;

use App\Services\SSH\SSHConnection;

final class RemoteReachProbe implements ReachProbe
{
    public const SECONDS = 20;

    private const CONFLICT = 409;

    public function __construct(
        private readonly SSHConnection $connection,
        private readonly ?DirectReach $direct,
    ) {}

    public static function script(): string
    {
        $url = (string) config('coresmith.address.reach_url');

        return implode("\n", [
            "echo '== inet6'",
            'cat /proc/net/if_inet6 2>/dev/null || true',
            "echo '== addresses'",
            "ip -o addr show 2>/dev/null | awk '{print \$4}' || true",
            "echo '== direct'",
            ...($url === '' ? [] : ['curl -4 -sS --max-time ' . self::SECONDS . ' -H ' . escapeshellarg('Accept: application/json') . ' ' . escapeshellarg($url) . ' 2>/dev/null || true']),
        ]);
    }

    public function direct(): ?DirectReach
    {
        return $this->direct;
    }

    public function host(string $host, string $from): ?HostReach
    {
        $url = (string) config('coresmith.address.reach_url');

        if ($url === '' || filter_var($from, FILTER_VALIDATE_IP) === false || preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/i', $host) !== 1) {
            return null;
        }

        $family = filter_var($from, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? '-6' : '-4';
        $command = "curl {$family} -sS --max-time " . self::SECONDS
            . ' --interface ' . escapeshellarg($from)
            . ' -H ' . escapeshellarg('Accept: application/json')
            . " -w '\\n%{http_code}' "
            . escapeshellarg($url . '?' . http_build_query(['host' => $host]))
            . ' 2>/dev/null || true';

        $lines = preg_split('/\r\n|\n|\r/', trim($this->connection->execute($command, timeout: self::SECONDS + 10)->output)) ?: [];
        $status = (int) array_pop($lines);
        $json = json_decode(implode("\n", $lines), true);
        $address = is_array($json) && is_string($json['address'] ?? null) ? $json['address'] : '';

        return match (true) {
            $status === self::CONFLICT => new HostReach($address, false, false, false),
            $status >= 200 && $status < 300 && is_array($json) => new HostReach(
                $address,
                true,
                (($json['http'] ?? [])['reachable'] ?? false) === true,
                (($json['https'] ?? [])['reachable'] ?? false) === true,
            ),
            default => null,
        };
    }
}
