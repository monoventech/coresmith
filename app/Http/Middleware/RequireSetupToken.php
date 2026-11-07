<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\Edition;
use App\Models\User;
use App\Services\Install\SetupToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequireSetupToken
{
    public function __construct(private readonly SetupToken $token) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_if(Edition::current()->isCloud(), 404);

        if (User::query()->exists()) {
            return redirect()->route('dashboard');
        }

        if (!$this->token->exists()) {
            abort(403, 'Setup token not found. Re-run the installer.');
        }

        $provided = $request->query('token') ?? $request->input('setup_token');

        abort_unless(
            $this->token->matches(is_string($provided) ? $provided : null),
            403,
            'Invalid setup link. Use the URL shown during installation.',
        );

        return $next($request);
    }
}
