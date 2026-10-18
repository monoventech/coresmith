<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

final class ContentSecurityPolicy
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!$this->applies($request)) {
            return $next($request);
        }

        $nonce = Vite::useCspNonce();

        $response = $next($request);

        $response->headers->set('Content-Security-Policy', self::policy($nonce));

        return $response;
    }

    public static function policy(string $nonce): string
    {
        $connect = trim("'self' " . (string) config('coresmith.websocket_origin'));

        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}'",
            "style-src 'self' 'unsafe-inline'",
            "connect-src {$connect}",
            "font-src 'self'",
            "img-src 'self' data:",
        ]);
    }

    private function applies(Request $request): bool
    {
        if (!config('coresmith.content_security_policy') || Vite::isRunningHot()) {
            return false;
        }

        $horizon = trim((string) config('horizon.path'), '/');

        return $horizon === '' || !$request->is($horizon, $horizon . '/*');
    }
}
