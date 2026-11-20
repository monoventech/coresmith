<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\ServerFrontDoor;
use App\Services\FrontDoor\FrontDoorAddresses;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

final class FrontDoorVisitorAddress
{
    public function handle(Request $request, Closure $next): Response
    {
        $peer = (string) $request->server->get('REMOTE_ADDR');

        if (IpUtils::checkIp($peer, FrontDoorAddresses::PREFIX . '.0.0/16')) {
            $visitor = self::visitor($request, $peer);

            if ($visitor !== null && ServerFrontDoor::forThisMachine()?->door_address === $peer) {
                $request->server->set('REMOTE_ADDR', $visitor);
            }
        }

        return $next($request);
    }

    private static function visitor(Request $request, string $peer): ?string
    {
        $hops = array_map(trim(...), explode(',', (string) $request->headers->get('X-Forwarded-For')));

        while ($hops !== [] && end($hops) === $peer) {
            array_pop($hops);
        }

        $visitor = end($hops);

        return is_string($visitor) && filter_var($visitor, FILTER_VALIDATE_IP) !== false ? $visitor : null;
    }
}
