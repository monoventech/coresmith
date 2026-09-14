<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Sso;

use App\Models\SsoIdentity;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class PendingSsoLink
{
    public const SESSION_KEY = 'sso_link';

    public const TTL = 600;

    public function __construct(private readonly AccountLinker $accounts) {}

    public function remember(Request $request, SsoLinkRequired $required): void
    {
        $request->session()->put(self::SESSION_KEY, $required->link + ['expires' => now()->getTimestamp() + self::TTL]);
    }

    public function email(Request $request): ?string
    {
        $link = $this->pending($request);

        return $link === null ? null : $link['email'];
    }

    public function complete(Request $request, User $user): ?SsoIdentity
    {
        $link = $this->pending($request);

        $request->session()->forget(self::SESSION_KEY);

        if ($link === null || Str::lower($user->email) !== $link['email']) {
            return null;
        }

        return $this->accounts->confirm($user, $link);
    }

    /**
     * @return array{team: string, issuer: string, connection_issuer: string, subject: string, email: string}|null
     */
    private function pending(Request $request): ?array
    {
        if (!$request->hasSession()) {
            return null;
        }

        $link = $request->session()->get(self::SESSION_KEY);

        if (!is_array($link) || (int) ($link['expires'] ?? 0) <= now()->getTimestamp()) {
            return null;
        }

        foreach (['team', 'issuer', 'connection_issuer', 'subject', 'email'] as $key) {
            if (!is_string($link[$key] ?? null) || $link[$key] === '') {
                return null;
            }
        }

        return [
            'team' => $link['team'],
            'issuer' => $link['issuer'],
            'connection_issuer' => $link['connection_issuer'],
            'subject' => $link['subject'],
            'email' => $link['email'],
        ];
    }
}
