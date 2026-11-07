<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Responses;

use App\Models\Team;
use App\Models\User;
use App\Services\Sso\PendingSsoLink;
use App\Services\Teams\TeamContext;
use App\Services\Teams\TeamUrls;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Fortify\Contracts\LoginResponse;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse;
use Laravel\Fortify\Fortify;
use Symfony\Component\HttpFoundation\Response;

final class TeamHomeResponse implements LoginResponse, TwoFactorLoginResponse
{
    public function __construct(private readonly TeamUrls $urls) {}

    /**
     * @param  Request  $request
     */
    public function toResponse($request): Response
    {
        $context = app(TeamContext::class);
        $user = $request->user();
        $linked = $user instanceof User ? app(PendingSsoLink::class)->complete($request, $user) : null;
        $addressed = $context->explicit() ? $context->team() : null;

        if ($addressed instanceof Team && $user instanceof User && !$user->belongsToTeam($addressed)) {
            return $this->refuse($request, $addressed);
        }

        if ($request->wantsJson()) {
            return $request->routeIs('two-factor.login*') ? new JsonResponse('', 204) : new JsonResponse(['two_factor' => false]);
        }

        $redirect = redirect()->intended($this->home($request, $context));

        return $linked === null ? $redirect : $redirect->with('success', 'Connected. Sign in with single sign-on from now on.');
    }

    private function refuse(Request $request, Team $team): Response
    {
        Auth::guard(config('fortify.guard'))->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $message = "That account isn't a member of {$team->name}. Sign in with one that is, or ask an owner to invite you.";

        if ($request->wantsJson()) {
            return new JsonResponse(['message' => $message, 'errors' => ['email' => [$message]]], 422);
        }

        return redirect()->route('login')->withErrors(['email' => $message]);
    }

    private function home(Request $request, TeamContext $context): string
    {
        $user = $request->user();

        if ($context->explicit() || !$user instanceof User) {
            return url(Fortify::redirects('login'));
        }

        $team = $user->teams->firstWhere('id', $user->current_team_id) ?? $user->teams->first();

        return $team === null ? url(Fortify::redirects('login')) : $this->urls->home($team, $request);
    }
}
