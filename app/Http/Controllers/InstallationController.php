<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\TeamRole;
use App\Models\InstanceSetting;
use App\Models\Team;
use App\Models\User;
use App\Services\Install\SetupToken;
use DateTimeZone;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

final class InstallationController extends Controller
{
    public const MIN_PASSWORD = 12;

    public function __construct(private readonly SetupToken $token) {}

    public function show(Request $request): Response
    {
        return Inertia::render('Install', [
            'setupToken' => (string) $request->query('token'),
            'timezones' => DateTimeZone::listIdentifiers(),
            'minPassword' => self::MIN_PASSWORD,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        if (is_string($request->input('email'))) {
            $request->merge(['email' => User::normaliseEmail($request->input('email'))]);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(self::MIN_PASSWORD)->uncompromised()],
            'instance_name' => ['required', 'string', 'max:64'],
            'timezone' => ['required', 'string', Rule::in(DateTimeZone::listIdentifiers())],
            'notification_email' => ['nullable', 'string', 'email', 'max:255'],
        ]);

        $user = DB::transaction(function () use ($validated): User {
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
            ]);

            $user->forceFill(['email_verified_at' => now()])->save();

            $team = Team::create([
                'name' => $validated['instance_name'],
                'owner_id' => $user->getKey(),
            ]);

            $team->members()->attach($user, ['role' => TeamRole::Owner->value]);

            InstanceSetting::put('instance_name', $validated['instance_name']);
            InstanceSetting::put('timezone', $validated['timezone']);
            InstanceSetting::put('notification_email', $validated['notification_email'] ?? null);
            InstanceSetting::put('installed_at', now()->toIso8601String());

            return $user;
        });

        $this->token->forget();

        event(new Registered($user));
        auth()->login($user);
        $request->session()->regenerate();

        return to_route('dashboard')->with('success', 'Coresmith is ready. Add a server to get started.');
    }
}
