<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Teams\TeamContext;
use App\Services\Teams\TeamUrls;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

final class ResolveTeamUrl
{
    public const ATTRIBUTE = 'coresmith.team';

    public function __construct(private readonly TeamUrls $urls) {}

    public function handle(Request $request, Closure $next): Response
    {
        $team = $this->urls->teamForHost($request->getHost());

        if ($team !== null) {
            $request->attributes->set(self::ATTRIBUTE, [$team->getKey(), TeamContext::HOST]);

            return $next($request);
        }

        $segment = $this->urls->mountableSegment($request->getPathInfo());
        $team = $segment === null ? null : $this->urls->teamForSlug($segment);

        if ($team === null) {
            return $next($request);
        }

        if (rtrim($request->getPathInfo(), '/') === '/' . $segment) {
            $query = $request->getQueryString();

            return redirect()->to($request->getSchemeAndHttpHost() . '/' . $segment . '/dashboard' . ($query === null ? '' : '?' . $query));
        }

        $mounted = $this->mount($request, $segment);
        $mounted->attributes->set(self::ATTRIBUTE, [$team->getKey(), TeamContext::PATH]);

        URL::useAssetOrigin(config('app.asset_url') ?: $request->getSchemeAndHttpHost());

        try {
            return $next($mounted);
        } finally {
            app()->instance('request', $request);
            URL::useAssetOrigin(config('app.asset_url') ?: null);
        }
    }

    private function mount(Request $request, string $segment): Request
    {
        $server = $request->server->all();
        $filename = (string) ($server['SCRIPT_FILENAME'] ?? '');
        $filename = $filename === '' ? public_path('index.php') : $filename;
        $script = '/' . $segment . '/' . basename($filename);

        $server['SCRIPT_FILENAME'] = $filename;
        $server['SCRIPT_NAME'] = $script;
        $server['PHP_SELF'] = $script;

        return $request->duplicate(server: $server);
    }
}
