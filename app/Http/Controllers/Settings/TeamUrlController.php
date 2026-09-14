<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\Teams\TeamContext;
use App\Services\Teams\TeamSlug;
use App\Services\Teams\TeamUrls;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

final class TeamUrlController extends Controller
{
    public function update(Request $request, TeamContext $context, TeamUrls $urls): Response
    {
        $team = $context->teamOrFail();

        $this->authorize('update', $team);

        $request->merge(['slug' => strtolower(trim((string) $request->input('slug')))]);

        $validated = $request->validate([
            'slug' => ['required', 'string', 'max:' . TeamSlug::MAX_LENGTH, function (string $attribute, mixed $value, Closure $fail) use ($team): void {
                $slug = (string) $value;

                if (!TeamSlug::isValid($slug)) {
                    $fail('Use lowercase letters, numbers and single hyphens, like monoven-labs.');
                } elseif (TeamSlug::isReserved($slug)) {
                    $fail('That address is reserved. Try another one.');
                } elseif (!TeamSlug::isAvailable($slug, $team->getKey())) {
                    $fail('Another team already uses that address.');
                }
            }],
        ]);

        $previous = $team->slug;

        if ($previous === $validated['slug']) {
            return back()->with('success', 'Saved.');
        }

        $team->forceFill(['slug' => $validated['slug']])->save();

        activity()->performedOn($team)->withProperties(['from' => $previous, 'to' => $team->slug])->log('team_url_changed');

        $request->session()->flash('success', 'Saved. Old links to ' . $previous . ' stop working.');

        return Inertia::location(match (true) {
            $context->viaHost() && $urls->tenantDomain() !== null && $request->getHost() === $previous . '.' . $urls->tenantDomain() => $urls->subdomainUrl($team) . '/settings/team',
            $context->viaPath() => $request->getSchemeAndHttpHost() . '/' . $team->slug . '/settings/team',
            default => route('teams.index'),
        });
    }
}
