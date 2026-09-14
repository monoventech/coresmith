<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Team;
use App\Models\User;
use App\Services\Teams\TeamContext;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureTeamContext
{
    public function __construct(private readonly TeamContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        [$teamId, $source] = $request->attributes->get(ResolveTeamUrl::ATTRIBUTE, [null, null]);

        $this->context->set(null, TeamContext::USER);

        if ($teamId !== null) {
            $team = Team::query()->find($teamId);

            abort_if($team === null, 404);

            if ($user instanceof User && !$user->belongsToTeam($team)) {
                abort_unless($request->routeIs('logout'), 404);
            } else {
                $this->context->set($team, (string) $source);
            }
        }

        if (!$user instanceof User) {
            return $next($request);
        }

        $this->followRouteModels($request, $user);

        if ($this->context->team() === null) {
            $this->context->set($this->fallback($user), TeamContext::USER);
        }

        $team = $this->context->team();

        if ($team !== null && $user->current_team_id !== $team->getKey()) {
            $user->forceFill(['current_team_id' => $team->getKey()])->saveQuietly();
        }

        return $next($request);
    }

    private function followRouteModels(Request $request, User $user): void
    {
        foreach ($request->route()?->parameters() ?? [] as $parameter) {
            $teamId = match (true) {
                $parameter instanceof Team => $parameter->getKey(),
                $parameter instanceof Model && array_key_exists('team_id', $parameter->getAttributes()) => $parameter->getAttribute('team_id'),
                default => null,
            };

            if ($teamId === null) {
                continue;
            }

            if ($this->context->explicit()) {
                abort_if($teamId !== $this->context->team()?->getKey(), 404);

                return;
            }

            $team = $user->teams->firstWhere('id', $teamId);

            if ($team instanceof Team) {
                $this->context->set($team, TeamContext::USER);
            }

            return;
        }
    }

    private function fallback(User $user): ?Team
    {
        $teams = $user->teams;

        return $teams->firstWhere('id', $user->current_team_id) ?? $teams->first();
    }
}
