<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Address;

use Illuminate\Support\Facades\Http;
use RuntimeException;

final class HttpCaddyAdmin implements CaddyAdmin
{
    public const ADMIN = 'http://localhost:2019';

    public function reload(): void
    {
        $path = (string) config('coresmith.address.caddyfile');
        $caddyfile = @file_get_contents($path);

        if (!is_string($caddyfile) || $caddyfile === '') {
            throw new RuntimeException("Coresmith couldn't read {$path}.");
        }

        $socket = (string) config('coresmith.address.caddy_admin_socket');

        $request = $socket === ''
            ? Http::withHeaders(['Origin' => self::ADMIN])
            : Http::withOptions(['curl' => [CURLOPT_UNIX_SOCKET_PATH => $socket]]);

        $response = $request
            ->withBody($caddyfile, 'text/caddyfile')
            ->timeout(30)
            ->post(($socket === '' ? self::ADMIN : 'http://localhost') . '/load');

        if ($response->failed()) {
            throw new RuntimeException('Caddy refused the new site: ' . trim((string) ($response->json('error') ?? $response->body())));
        }
    }
}
