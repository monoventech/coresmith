<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Panel;

final class Realtime
{
    public static function available(): bool
    {
        return !in_array((string) config('broadcasting.default'), ['null', 'log', ''], true);
    }

    /**
     * @return array{key: string, host: string, port: int, tls: bool}|null
     */
    public static function socket(): ?array
    {
        $socket = (array) config('coresmith.live.socket');
        $key = (string) ($socket['key'] ?? '');

        return $key === '' ? null : [
            'key' => $key,
            'host' => (string) ($socket['host'] ?? ''),
            'port' => (int) ($socket['port'] ?? 443),
            'tls' => ($socket['scheme'] ?? 'https') === 'https',
        ];
    }
}
