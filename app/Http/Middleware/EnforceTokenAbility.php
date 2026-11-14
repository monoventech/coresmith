<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\Ability;
use App\Models\ApiToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnforceTokenAbility
{
    public function handle(Request $request, Closure $next, string $ability): Response
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();

        if (!$token instanceof ApiToken) {
            return $this->deny('This endpoint requires an API token.', 403);
        }

        $team = $token->team;

        if ($team === null) {
            return $this->deny('This token isn\'t tied to a team. Create a new one.', 403);
        }

        if (!$token->can($ability)) {
            return $this->deny("This token does not carry the {$ability} ability.", 403);
        }

        $required = Ability::tryFrom($ability);

        if ($required === null || !$user->hasTeamAbility($team, $required)) {
            return $this->deny('Your role in this team does not allow that, so no token can.', 403);
        }

        $request->attributes->set('api_team', $team);

        return $next($request);
    }

    private function deny(string $message, int $status): Response
    {
        return response()->json(['message' => $message], $status);
    }
}
