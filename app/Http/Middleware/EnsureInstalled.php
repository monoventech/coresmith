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
use Inertia\Inertia;
use Laravel\Fortify\Features;
use Symfony\Component\HttpFoundation\Response;

final class EnsureInstalled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Edition::current()->isCloud() || Features::enabled(Features::registration()) || $request->is('install*') || User::query()->exists()) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Coresmith is installed but not yet configured.'], 503);
        }

        return Inertia::render('auth/SetupRequired')->toResponse($request)->setStatusCode(503);
    }
}
