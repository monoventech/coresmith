<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\Edition;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureEmailIsVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (Edition::current()->isSelfHosted() || !$user instanceof User || $user->hasVerifiedEmail()) {
            return $next($request);
        }

        return $request->expectsJson()
            ? response()->json(['message' => 'Confirm your email address first. The link is in your inbox.'], 403)
            : redirect()->guest(route('verification.notice'));
    }
}
