<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Enums\Edition;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Services\Teams\TeamInvitations;
use App\Services\Teams\TeamUrls;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

final class InvitationController extends Controller
{
    use PasswordValidationRules, ProfileValidationRules;

    public function __construct(private readonly TeamInvitations $invitations) {}

    public function show(Request $request, string $token): Response
    {
        $invitation = TeamInvitation::usable($token);

        if (!$invitation instanceof TeamInvitation) {
            return Inertia::render('auth/AcceptInvitation', ['state' => 'expired', 'invitation' => null, 'action' => null, 'trust_note' => false]);
        }

        $user = $request->user();

        $state = match (true) {
            $user instanceof User => Str::lower((string) $user->email) === $invitation->email ? 'join' : 'other',
            $this->accountFor($invitation) instanceof User => 'sign-in',
            default => 'register',
        };

        if ($state === 'sign-in') {
            redirect()->setIntendedUrl($this->invitations->link($token));
        }

        return Inertia::render('auth/AcceptInvitation', [
            'state' => $state,
            'invitation' => [
                'team' => $invitation->team?->name,
                'email' => $invitation->email,
                'role' => $invitation->role->label(),
                'inviter' => $invitation->inviter?->name,
            ],
            'action' => match ($state) {
                'register' => route('invitations.register', $token),
                'join' => route('invitations.accept', $token),
                default => null,
            },
            'trust_note' => Edition::current()->isSelfHosted(),
        ]);
    }

    public function register(Request $request, string $token): HttpResponse
    {
        $invitation = TeamInvitation::usable($token);

        if (!$invitation instanceof TeamInvitation || $this->accountFor($invitation) instanceof User) {
            return to_route('invitations.show', $token);
        }

        $validated = $request->validate([
            'name' => $this->nameRules(),
            'password' => $this->passwordRules(),
        ]);

        $user = DB::transaction(function () use ($invitation, $validated, $token): User {
            $user = new User;
            $user->forceFill([
                'name' => $validated['name'],
                'email' => $invitation->email,
                'password' => $validated['password'],
                'email_verified_at' => now(),
            ])->save();

            if (!$this->invitations->join($invitation, $user)) {
                throw new HttpResponseException(to_route('invitations.show', $token));
            }

            return $user;
        });

        event(new Registered($user));

        Auth::login($user);
        $request->session()->regenerate();

        return $this->open($request, $invitation->team);
    }

    public function accept(Request $request, string $token): HttpResponse
    {
        $invitation = TeamInvitation::usable($token);
        $user = $request->user();

        if (!$invitation instanceof TeamInvitation || Str::lower((string) $user->email) !== $invitation->email) {
            return to_route('invitations.show', $token);
        }

        if (!$this->invitations->join($invitation, $user)) {
            return to_route('invitations.show', $token);
        }

        return $this->open($request, $invitation->team);
    }

    private function accountFor(TeamInvitation $invitation): ?User
    {
        return User::query()->whereRaw('lower(email) = ?', [$invitation->email])->first();
    }

    private function open(Request $request, ?Team $team): HttpResponse
    {
        abort_unless($team instanceof Team, 404);

        $request->session()->flash('success', "Welcome to {$team->name}.");

        return Inertia::location(app(TeamUrls::class)->home($team, $request));
    }
}
