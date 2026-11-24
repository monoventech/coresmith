<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Terminal;

use App\Models\Server;
use App\Services\Caddy\CaddyApiClient;
use App\Services\Server\FirewallManagerFactory;
use App\Services\SSH\SSHConnection;
use Throwable;

final class ServerFingerprint
{
    public const MARK = 'cs-fingerprint';

    public const SECONDS = 30;

    public function script(Server $server): string
    {
        $mark = self::MARK;
        $hash = 'sha256sum | cut -c1-16';
        $key = escapeshellarg(explode(' ', trim((string) $server->ssh_public_key))[1] ?? 'no-key');
        $user = escapeshellarg((string) $server->ssh_user);
        $firewall = rescue(fn(): string => FirewallManagerFactory::for($server)->status(), 'true', report: false);
        $web = 'curl -fsS --max-time 5 ' . CaddyApiClient::address($server->caddyAdminOnSocket(), '/config/');

        return implode("\n", [
            'home=$(getent passwd ' . $user . ' | cut -d: -f6)',
            "printf '{$mark} key=%s\\n' \"\$(grep -cF {$key} \"\$home/.ssh/authorized_keys\" 2>/dev/null || true)\"",
            "printf '{$mark} sudo=%s\\n' \"\$(cat /etc/sudoers /etc/sudoers.d/* 2>/dev/null | {$hash})\"",
            "printf '{$mark} units=%s\\n' \"\$(for f in /etc/systemd/system/coresmith-*; do [ -f \"\$f\" ] && echo \"\$f\" && cat \"\$f\"; done 2>/dev/null | {$hash})\"",
            "printf '{$mark} settings=%s\\n' \"\$(find /etc/coresmith -type f 2>/dev/null | sort | xargs -r sha256sum 2>/dev/null | {$hash})\"",
            "printf '{$mark} web=%s\\n' \"\$({$web} 2>/dev/null | {$hash})\"",
            "printf '{$mark} firewall=%s\\n' \"\$({$firewall} 2>/dev/null | {$hash})\"",
            "printf '{$mark} running=%s\\n' \"\$(systemctl is-active caddy 2>/dev/null)\"",
            "printf '{$mark} processes=%s\\n' \"\$(systemctl list-units 'coresmith-app-*' --type=service --state=active --no-legend --plain 2>/dev/null | awk '{print \$1}' | sed 's/\\.service\$//' | sort | paste -sd, -)\"",
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function take(SSHConnection $connection, Server $server): array
    {
        try {
            return self::parse($connection->execute($this->script($server), sudo: true, timeout: self::SECONDS)->output);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return array<string, string>
     */
    public static function parse(string $output): array
    {
        $found = [];

        foreach (preg_split('/\r\n|\n|\r/', $output) ?: [] as $line) {
            if (preg_match('/^' . preg_quote(self::MARK, '/') . ' ([a-z]+)=(.*)$/', trim($line), $match) === 1) {
                $found[$match[1]] = trim($match[2]);
            }
        }

        return $found;
    }
}
