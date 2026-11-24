<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Terminal;

use App\Models\TerminalSession;
use Illuminate\Support\Facades\Cache;

final class TerminalInbox
{
    public const TTL_SECONDS = 600;

    public const MESSAGES = 500;

    /**
     * @param  array{type: string}&array<string, mixed>  $message
     */
    public function push(TerminalSession $session, array $message): void
    {
        $key = self::key($session);

        Cache::lock($key . ':lock', 5)->block(2, function () use ($key, $message): void {
            $messages = Cache::get($key, []);
            $messages = is_array($messages) ? $messages : [];

            if (count($messages) < self::MESSAGES) {
                $messages[] = $message;
            }

            Cache::put($key, $messages, self::TTL_SECONDS);
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function drain(TerminalSession $session): array
    {
        $key = self::key($session);

        if (!Cache::has($key)) {
            return [];
        }

        return Cache::lock($key . ':lock', 5)->block(2, function () use ($key): array {
            $messages = Cache::pull($key, []);

            return is_array($messages) ? array_values($messages) : [];
        });
    }

    private static function key(TerminalSession $session): string
    {
        return 'terminal:' . $session->getKey() . ':in';
    }
}
