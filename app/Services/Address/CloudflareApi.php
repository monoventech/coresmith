<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Address;

use App\Exceptions\CloudflareRefused;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use SensitiveParameter;

final class CloudflareApi
{
    public const UNREACHABLE = "Coresmith couldn't reach Cloudflare, so nothing changed. Try again in a minute.";

    private const TIMEOUT_SECONDS = 15;

    /**
     * @param  array<string, scalar>  $query
     * @return array<string, mixed>
     */
    public function get(#[SensitiveParameter] string $token, string $path, array $query = []): array
    {
        return $this->send($token, 'get', $path, $query);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function send(#[SensitiveParameter] string $token, string $method, string $path, array $data = []): array
    {
        $api = rtrim((string) config('coresmith.address.cloudflare_api'), '/');

        if ($api === '') {
            throw new RuntimeException(self::UNREACHABLE);
        }

        try {
            $response = Http::withToken($token)->acceptJson()->timeout(self::TIMEOUT_SECONDS)->{$method}($api . $path, $data);
        } catch (ConnectionException) {
            throw new RuntimeException(self::UNREACHABLE);
        }

        if (!$response->successful() || $response->json('success') !== true) {
            throw new CloudflareRefused('Cloudflare refused the change: ' . self::reason($response) . '.', $response->status());
        }

        return (array) $response->json();
    }

    private static function reason(Response $response): string
    {
        return rtrim((string) ($response->json('errors.0.message') ?? "HTTP {$response->status()}"), '.');
    }
}
