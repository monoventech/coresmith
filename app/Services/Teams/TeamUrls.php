<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Teams;

use App\Models\Team;
use App\Models\TeamDomain;
use Illuminate\Http\Request;

final class TeamUrls
{
    public function mainHost(): string
    {
        return strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
    }

    public function tenantDomain(): ?string
    {
        $domain = strtolower(trim((string) config('coresmith.tenant_domain'), ". \t\n\r\0\x0B"));

        return $domain === '' ? null : $domain;
    }

    public function teamForHost(string $host): ?Team
    {
        $host = strtolower(rtrim($host, '.'));

        if ($host === '' || $host === $this->mainHost() || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return null;
        }

        $tenant = $this->tenantDomain();

        if ($tenant !== null && str_ends_with($host, '.' . $tenant)) {
            $label = substr($host, 0, -strlen('.' . $tenant));

            return TeamSlug::isValid($label) ? $this->teamForSlug($label) : null;
        }

        return TeamDomain::query()
            ->where('hostname', $host)
            ->whereNotNull('verified_at')
            ->with('team')
            ->first()
            ?->team;
    }

    public function teamForSlug(string $slug): ?Team
    {
        return TeamSlug::isValid($slug) ? Team::query()->where('slug', $slug)->first() : null;
    }

    public function mountableSegment(string $pathInfo): ?string
    {
        $segment = explode('/', ltrim($pathInfo, '/'))[0];

        if ($segment === '' || !TeamSlug::isValid($segment) || TeamSlug::isReserved($segment)) {
            return null;
        }

        return $segment;
    }

    public function canonical(string $path = '/'): string
    {
        return rtrim((string) config('app.url'), '/') . '/' . ltrim($path, '/');
    }

    public function pathUrl(Team $team, ?Request $request = null): string
    {
        return $this->root($request) . '/' . $team->slug;
    }

    public function subdomainUrl(Team $team): ?string
    {
        $tenant = $this->tenantDomain();

        if ($tenant === null) {
            return null;
        }

        $scheme = parse_url((string) config('app.url'), PHP_URL_SCHEME) ?: 'https';

        return $scheme . '://' . $team->slug . '.' . $tenant;
    }

    public function home(Team $team, ?Request $request = null): string
    {
        return $this->pathUrl($team, $request) . '/dashboard';
    }

    private function root(?Request $request): string
    {
        $context = app(TeamContext::class);

        if ($request === null || $context->viaHost() || $this->teamForHost($request->getHost()) !== null) {
            return rtrim((string) config('app.url'), '/');
        }

        return $request->getSchemeAndHttpHost();
    }
}
