<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Server;

use App\Models\Server;
use App\Services\SSH\SSHConnectionManager;
use RuntimeException;

final class CoresmithKey
{
    public const REMOVED = 'removed';

    public const FAILED = 'failed';

    public const NEVER_REACHED = 'never_reached';

    public const SECONDS = 10;

    public function __construct(private readonly SSHConnectionManager $connections) {}

    public function takeOff(Server $server): string
    {
        if ($server->last_connected_at === null || $server->ip_address === null) {
            return self::NEVER_REACHED;
        }

        $connection = $this->connections->brief($server, self::SECONDS);

        try {
            return $connection->execute($this->command($server), timeout: self::SECONDS)->successful() ? self::REMOVED : self::FAILED;
        } catch (RuntimeException) {
            return self::FAILED;
        } finally {
            $connection->disconnect();
        }
    }

    public function command(Server $server): string
    {
        $key = escapeshellarg(explode(' ', trim((string) $server->ssh_public_key))[1] ?? '');

        return implode('; ', [
            'f="$HOME/.ssh/authorized_keys"',
            '[ -f "$f" ] || exit 0',
            'tmp=$(mktemp "$f.XXXXXX") || exit 1',
            "grep -v -F {$key} \"\$f\" > \"\$tmp\"",
            's=$?',
            'if [ "$s" -gt 1 ]; then rm -f "$tmp"; exit 1; fi',
            'chmod 600 "$tmp" && mv -f "$tmp" "$f"',
        ]);
    }

    public static function file(Server $server): string
    {
        return ($server->ssh_user === 'root' ? '/root' : '/home/' . $server->ssh_user) . '/.ssh/authorized_keys';
    }

    public static function comment(Server $server): string
    {
        return explode(' ', trim((string) $server->ssh_public_key), 3)[2] ?? 'coresmith';
    }
}
