<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\ServerTunnel;
use App\Services\Caddy\CaddyApiClient;
use App\Services\Network\CloudflareRanges;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

final class CloudflareVisitorAddress
{
    public function handle(Request $request, Closure $next): Response
    {
        $visitor = trim((string) $request->headers->get('CF-Connecting-IP'));
        $peer = (string) $request->server->get('REMOTE_ADDR');

        if (filter_var($visitor, FILTER_VALIDATE_IP) !== false && (CloudflareRanges::contains($peer) || (self::loopback($peer) && ServerTunnel::thisMachineOpen()))) {
            $request->server->set('REMOTE_ADDR', $visitor);
        }

        return $next($request);
    }

    private static function loopback(string $peer): bool
    {
        return IpUtils::checkIp($peer, CaddyApiClient::LOOPBACK);
    }
}
