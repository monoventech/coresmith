<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Notifications\Preferences;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class NotificationSettingsController extends Controller
{
    public function edit(Request $request): Response
    {
        $preferences = Preferences::for($request->user());

        return Inertia::render('settings/Notifications', [
            'email' => $request->user()->email,
            'toggles' => array_map(fn(string $key): array => [
                'key' => $key,
                'label' => Preferences::LABELS[$key],
                'description' => Preferences::DESCRIPTIONS[$key],
                'on' => $preferences[$key],
                'locked' => in_array($key, Preferences::LOCKED, true),
            ], array_keys(Preferences::DEFAULTS)),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'key' => ['required', Rule::in(array_keys(Preferences::DEFAULTS))],
            'on' => ['required', 'boolean'],
        ]);

        if (in_array($validated['key'], Preferences::LOCKED, true)) {
            return back()->with('error', 'Failures always notify. That one can\'t be turned off.');
        }

        $preferences = Preferences::for($request->user());
        $preferences[$validated['key']] = (bool) $validated['on'];

        $request->user()->forceFill(['notification_preferences' => $preferences])->save();

        return back()->with('success', 'Saved.');
    }
}
