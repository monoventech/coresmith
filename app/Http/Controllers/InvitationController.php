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
use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use PDOException;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

final class InvitationController extends Controller
{
    use DetectsConcurrencyErrors, PasswordValidationRules, ProfileValidationRules;

    public function __construct(private readonly TeamInvitations $invitations) {}

    public function show(Request $request, string $token): Response
    {
        $invitation = TeamInvitation::usable($token);

        if (!$invitation instanceof TeamInvitation) {
            return Inertia::render('auth/AcceptInvitation', ['state' => 'expired', 'invitation' => null, 'action' => null, 'trust_note' => false]);
        }

        $user = $request->user();

        $state = match (true) {
            $user instanceof User => $invitation->isFor($user) ? 'join' : 'other',
            User::findByEmail($invitation->email) instanceof User => 'sign-in',
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

        if (!$invitation instanceof TeamInvitation || User::findByEmail($invitation->email) instanceof User) {
            return to_route('invitations.show', $token);
        }

        $validated = $request->validate([
            'name' => $this->nameRules(),
            'password' => $this->passwordRules(),
        ]);

        try {
            $user = DB::transaction(function () use ($invitation, $validated, $token): User {
                $user = new User;
                $user->forceFill([
                    'name' => $validated['name'],
                    'email' => $invitation->email,
                    'password' => $validated['password'],
                ])->save();

                if (!$this->invitations->join($invitation, $user)) {
                    throw new HttpResponseException(to_route('invitations.show', $token));
                }

                return $user;
            });
        } catch (PDOException $exception) {
            return $this->lostRace($exception, $token);
        }

        $this->invitations->syncKeys($invitation);

        event(new Registered($user));

        Auth::login($user);
        $request->session()->regenerate();

        return $this->open($request, $invitation->team);
    }

    public function accept(Request $request, string $token): HttpResponse
    {
        $invitation = TeamInvitation::usable($token);

        if (!$invitation instanceof TeamInvitation) {
            return to_route('invitations.show', $token);
        }

        try {
            $joined = $this->invitations->join($invitation, $request->user());
        } catch (PDOException $exception) {
            return $this->lostRace($exception, $token);
        }

        if (!$joined) {
            return to_route('invitations.show', $token);
        }

        $this->invitations->syncKeys($invitation);

        return $this->open($request, $invitation->team);
    }

    private function lostRace(PDOException $exception, string $token): HttpResponse
    {
        if (!$exception instanceof UniqueConstraintViolationException && !$this->causedByConcurrencyError($exception)) {
            throw $exception;
        }

        return to_route('invitations.show', $token);
    }

    private function open(Request $request, ?Team $team): HttpResponse
    {
        abort_unless($team instanceof Team, 404);

        $request->session()->flash('success', "Welcome to {$team->name}.");

        return Inertia::location(app(TeamUrls::class)->home($team, $request));
    }
}
