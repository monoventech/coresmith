<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RecordLastActive
{
    public const MINUTES = 10;

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && ($user->last_active_at === null || $user->last_active_at->lte(now()->subMinutes(self::MINUTES)))) {
            $now = now();

            User::query()->whereKey($user->getKey())->toBase()->update(['last_active_at' => $now]);
            $user->setAttribute('last_active_at', $now);
            $user->syncOriginalAttribute('last_active_at');
        }

        return $next($request);
    }
}
