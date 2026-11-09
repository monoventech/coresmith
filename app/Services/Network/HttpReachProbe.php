<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Network;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

final class HttpReachProbe implements ReachProbe
{
    private const SECONDS = 20;

    private const CONFLICT = 409;

    public function direct(): ?DirectReach
    {
        $response = $this->ask([], [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]);

        if ($response === null || !$response->successful()) {
            return null;
        }

        return new DirectReach((string) $response->json('address'), $response->json('ports.80') === true, $response->json('ports.443') === true);
    }

    public function host(string $host, string $from): ?HostReach
    {
        $family = filter_var($from, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? CURL_IPRESOLVE_V6 : CURL_IPRESOLVE_V4;
        $response = $this->ask(['host' => $host], [CURLOPT_IPRESOLVE => $family, CURLOPT_INTERFACE => $from]);

        if ($response === null) {
            return null;
        }

        if ($response->status() === self::CONFLICT) {
            return new HostReach((string) $response->json('address'), false, false, false);
        }

        if (!$response->successful()) {
            return null;
        }

        return new HostReach((string) $response->json('address'), true, $response->json('http.reachable') === true, $response->json('https.reachable') === true);
    }

    /**
     * @param  array<string, string>  $query
     * @param  array<int, mixed>  $curl
     */
    private function ask(array $query, array $curl): ?Response
    {
        $url = (string) config('coresmith.address.reach_url');

        if ($url === '') {
            return null;
        }

        try {
            return Http::timeout(self::SECONDS)->withOptions(['curl' => $curl])->acceptJson()->get($url, $query);
        } catch (Throwable) {
            return null;
        }
    }
}
