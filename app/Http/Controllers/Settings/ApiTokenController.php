<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Enums\Ability;
use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class ApiTokenController extends Controller
{
    /** @var list<string> */
    public const OFFERED = [
        Ability::ViewServer->value,
        Ability::ViewApplication->value,
        Ability::DeployApplication->value,
        Ability::UpdateApplication->value,
        Ability::ViewApplicationLogs->value,
        Ability::ViewDatabase->value,
    ];

    public function index(Request $request): Response
    {
        $user = $request->user();
        $team = $this->currentTeam();

        abort_if($team === null, 404);

        return Inertia::render('settings/ApiTokens', [
            'team' => ['id' => $team->id, 'name' => $team->name],
            'tokens' => ApiToken::query()
                ->where('tokenable_type', $user->getMorphClass())
                ->where('tokenable_id', $user->getKey())
                ->latest()
                ->get()
                ->map(fn(ApiToken $token): array => [
                    'id' => $token->getKey(),
                    'name' => $token->name,
                    'abilities' => array_values((array) $token->abilities),
                    'last_used_at' => $token->last_used_at?->toIso8601String(),
                    'created_at' => $token->created_at?->toIso8601String(),
                    'team_id' => $token->team_id,
                ])->values(),
            'abilities' => collect(self::OFFERED)->map(fn(string $value): array => [
                'value' => $value,
                'granted' => $user->hasTeamAbility($team, Ability::from($value)),
            ])->values(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        $team = $this->currentTeam();

        abort_if($team === null, 404);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'abilities' => ['required', 'array', 'min:1'],
            'abilities.*' => ['string', Rule::in(self::OFFERED)],
        ]);

        $granted = array_values(array_filter(
            $validated['abilities'],
            fn(string $value): bool => $user->hasTeamAbility($team, Ability::from($value)),
        ));

        if ($granted === []) {
            return back()->with('error', 'Your role in this team grants none of those abilities, so the token would be useless.');
        }

        $token = $user->createToken($validated['name'], $granted);

        ApiToken::whereKey($token->accessToken->getKey())->update(['team_id' => $team->getKey()]);

        return back()->with([
            'success' => 'Token created. Copy it now, it can\'t be shown again.',
            'plainTextToken' => $token->plainTextToken,
        ]);
    }

    public function destroy(Request $request, string $token): RedirectResponse
    {
        $user = $request->user();

        $deleted = ApiToken::query()
            ->whereKey($token)
            ->where('tokenable_type', $user->getMorphClass())
            ->where('tokenable_id', $user->getKey())
            ->delete();

        abort_if($deleted === 0, 404);

        return back()->with('success', 'Token revoked.');
    }
}
