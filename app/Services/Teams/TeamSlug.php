<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Teams;

use App\Models\Team;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Illuminate\Support\Str;

final class TeamSlug
{
    public const PATTERN = '/^[a-z0-9](?:[a-z0-9-]{0,38}[a-z0-9])?$/D';

    public const MAX_LENGTH = 40;

    /** @var list<string> */
    public const RESERVED = [
        'about',
        'account',
        'admin',
        'api',
        'app',
        'apps',
        'assets',
        'auth',
        'billing',
        'blog',
        'broadcasting',
        'build',
        'caddy',
        'cdn',
        'cloud',
        'coresmith',
        'current-team',
        'dashboard',
        'deployments',
        'docs',
        'email',
        'forgot-password',
        'help',
        'home',
        'horizon',
        'install',
        'invite',
        'livewire',
        'login',
        'logout',
        'mail',
        'new',
        'panel',
        'password',
        'preview',
        'pricing',
        'pulse',
        'register',
        'releases',
        'reset-password',
        'root',
        'sanctum',
        'security',
        'servers',
        'settings',
        'setup',
        'sso',
        'static',
        'status',
        'storage',
        'support',
        'teams',
        'telescope',
        'two-factor-challenge',
        'up',
        'user',
        'webhooks',
        'www',
    ];

    /** @var list<string>|null */
    private static ?array $routeSegments = null;

    public static function isValid(string $slug): bool
    {
        return preg_match(self::PATTERN, $slug) === 1 && !str_contains($slug, '--');
    }

    public static function isReserved(string $slug): bool
    {
        return in_array($slug, self::RESERVED, true) || in_array($slug, self::routeSegments(), true);
    }

    public static function isAvailable(string $slug, ?string $ignoreTeamId = null): bool
    {
        if (!self::isValid($slug) || self::isReserved($slug)) {
            return false;
        }

        return !Team::withTrashed()
            ->where('slug', $slug)
            ->when($ignoreTeamId !== null, fn($query) => $query->whereKeyNot($ignoreTeamId))
            ->exists();
    }

    public static function generate(string $name, ?string $ignoreTeamId = null): string
    {
        $base = trim(Str::limit(Str::slug($name), self::MAX_LENGTH - 4, ''), '-');
        $base = preg_replace('/-{2,}/', '-', $base) ?? '';

        if ($base === '' || !self::isValid($base)) {
            $base = 'team';
        }

        if (self::isAvailable($base, $ignoreTeamId)) {
            return $base;
        }

        for ($suffix = 2; $suffix < 1000; $suffix++) {
            $candidate = $base . '-' . $suffix;

            if (self::isAvailable($candidate, $ignoreTeamId)) {
                return $candidate;
            }
        }

        return $base . '-' . Str::lower(Str::random(6));
    }

    /**
     * @return list<string>
     */
    public static function routeSegments(): array
    {
        if (self::$routeSegments !== null) {
            return self::$routeSegments;
        }

        $segments = [];

        foreach (Router::getRoutes()->getRoutes() as $route) {
            if (!$route instanceof Route) {
                continue;
            }

            $first = explode('/', trim($route->uri(), '/'))[0];

            if ($first !== '' && !str_starts_with($first, '{')) {
                $segments[$first] = true;
            }
        }

        return self::$routeSegments = array_keys($segments);
    }

    public static function forgetRouteSegments(): void
    {
        self::$routeSegments = null;
    }
}
