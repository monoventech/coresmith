<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Terminal;

use App\Models\TerminalSession;
use Illuminate\Support\Facades\Cache;

final class TerminalScreen
{
    public const BYTES = 262_144;

    public const TTL_SECONDS = 300;

    public function save(TerminalSession $session, string $screen, int $seq): void
    {
        Cache::put(self::key($session), ['screen' => base64_encode(substr($screen, -self::BYTES)), 'seq' => $seq], self::TTL_SECONDS);
    }

    /**
     * @return array{screen: string, seq: int}
     */
    public function read(TerminalSession $session): array
    {
        $saved = Cache::get(self::key($session));

        return is_array($saved) ? ['screen' => (string) ($saved['screen'] ?? ''), 'seq' => (int) ($saved['seq'] ?? 0)] : ['screen' => '', 'seq' => 0];
    }

    public function forget(TerminalSession $session): void
    {
        Cache::forget(self::key($session));
    }

    private static function key(TerminalSession $session): string
    {
        return 'terminal:' . $session->getKey() . ':screen';
    }
}
