<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Models\TeamSsoConnection;
use App\Services\Sso\PendingSsoLink;
use App\Services\Sso\SignIn;
use App\Services\Sso\SsoConnections;
use App\Services\Sso\SsoException;
use App\Services\Sso\SsoLinkRequired;
use App\Services\Teams\TeamContext;
use App\Services\Teams\TeamUrls;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

final class SingleSignOnController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('auth/SingleSignOn');
    }

    public function lookup(Request $request, SsoConnections $connections): SymfonyResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
        ], [
            'email.required' => 'Enter your work email address.',
            'email.email' => 'Enter a full email address, like you@example.com.',
        ]);

        $connection = $connections->findForEmail($validated['email']);

        if (!$connection instanceof TeamSsoConnection) {
            throw ValidationException::withMessages([
                'email' => 'No team signs in with single sign-on for that email domain. Sign in with your password instead.',
            ]);
        }

        return Inertia::location($connections->startUrl($request, $connection->team));
    }

    public function start(Request $request, Team $team, TeamContext $context, SsoConnections $connections, SignIn $signIn): SymfonyResponse
    {
        abort_if($context->explicit() && $context->team()?->getKey() !== $team->getKey(), 404);

        $connection = $connections->forTeam($team);

        if (!$connection instanceof TeamSsoConnection || !$connection->isUsable()) {
            return $this->failed($request, $context, $team, new SsoException('Single sign-on is not turned on for that team. Sign in with your password.', 'disabled'));
        }

        try {
            return Inertia::location($signIn->authorizationUrl($request, $team, $connection));
        } catch (SsoException $e) {
            return $this->failed($request, $context, $team, $e);
        }
    }

    public function callback(Request $request, TeamContext $context, TeamUrls $urls, SignIn $signIn, PendingSsoLink $links): RedirectResponse
    {
        try {
            $identity = $signIn->complete($request);
        } catch (SsoLinkRequired $e) {
            $links->remember($request, $e);

            Log::notice('Single sign-on is waiting for a password sign-in to link an account.', ['team' => $e->team->getKey()]);

            return redirect()->to($this->loginUrl($request, $context, $e->team));
        } catch (SsoException $e) {
            return $this->failed($request, $context, $e->team, $e);
        }

        $user = $identity->user;
        $team = Team::query()->findOrFail($identity->team_id);

        Auth::login($user);
        $request->session()->regenerate();

        $user->forceFill(['current_team_id' => $team->getKey()])->save();
        $identity->forceFill(['last_login_at' => now()])->save();

        activity()
            ->performedOn($team)
            ->causedBy($user)
            ->withProperties(['issuer' => $identity->issuer, 'email' => $identity->email])
            ->log('sso_signed_in');

        return redirect()->intended($context->viaHost() ? $request->getSchemeAndHttpHost() . '/dashboard' : $urls->home($team, $request));
    }

    private function failed(Request $request, TeamContext $context, ?Team $team, SsoException $e): RedirectResponse
    {
        Log::notice('Single sign-on did not complete.', ['reason' => $e->reason, 'team' => $team?->getKey()]);

        return redirect()->to($this->loginUrl($request, $context, $team))->with('error', $e->getMessage());
    }

    private function loginUrl(Request $request, TeamContext $context, ?Team $team): string
    {
        return $request->getSchemeAndHttpHost() . ($team === null || $context->viaHost() ? '/login' : '/' . $team->slug . '/login');
    }
}
