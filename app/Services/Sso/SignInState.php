<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Sso;

use App\Models\Team;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class SignInState
{
    public const SESSION_KEY = 'sso_flows';

    public const TTL = 600;

    public const MAX_PENDING = 5;

    /**
     * @return array{state: string, nonce: string, verifier: string, challenge: string}
     */
    public function begin(Request $request, Team $team, string $host): array
    {
        $state = Str::random(40);
        $nonce = Str::random(40);
        $verifier = Str::random(64);
        $now = now()->getTimestamp();

        $pending = array_filter(
            (array) $request->session()->get(self::SESSION_KEY, []),
            fn(mixed $flow): bool => is_array($flow) && (int) ($flow['expires'] ?? 0) > $now,
        );

        $pending[$state] = [
            'team' => $team->getKey(),
            'host' => $host,
            'nonce' => $nonce,
            'verifier' => $verifier,
            'expires' => $now + self::TTL,
        ];

        $request->session()->put(self::SESSION_KEY, array_slice($pending, -self::MAX_PENDING, null, true));

        return ['state' => $state, 'nonce' => $nonce, 'verifier' => $verifier, 'challenge' => self::challenge($verifier)];
    }

    /**
     * @return array{team: string, host: string, nonce: string, verifier: string, expires: int}|null
     */
    public function consume(Request $request, mixed $state): ?array
    {
        $pending = (array) $request->session()->get(self::SESSION_KEY, []);

        if (!is_string($state) || preg_match('/^[A-Za-z0-9]{40}$/', $state) !== 1 || !is_array($pending[$state] ?? null)) {
            return null;
        }

        $flow = $pending[$state];
        unset($pending[$state]);

        $request->session()->put(self::SESSION_KEY, $pending);

        if ((int) ($flow['expires'] ?? 0) <= now()->getTimestamp()) {
            return null;
        }

        return [
            'team' => (string) ($flow['team'] ?? ''),
            'host' => (string) ($flow['host'] ?? ''),
            'nonce' => (string) ($flow['nonce'] ?? ''),
            'verifier' => (string) ($flow['verifier'] ?? ''),
            'expires' => (int) $flow['expires'],
        ];
    }

    public static function challenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }
}
