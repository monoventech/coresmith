<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\Edition;
use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

final class ThrottleSignups
{
    private const DECAY_SECONDS = 3600;

    public function __construct(private readonly RateLimiter $limiter) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (Edition::current()->isSelfHosted() || !$request->isMethod('post') || !$request->routeIs('register.store')) {
            return $next($request);
        }

        $key = 'signup:' . $request->ip();

        if ($this->limiter->tooManyAttempts($key, (int) config('coresmith.billing.signups_per_hour', 10))) {
            throw ValidationException::withMessages(['email' => 'Too many accounts were created from this address. Try again in an hour.']);
        }

        $this->limiter->hit($key, self::DECAY_SECONDS);

        return $next($request);
    }
}
