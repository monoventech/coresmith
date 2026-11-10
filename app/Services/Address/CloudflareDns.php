<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Address;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class CloudflareDns
{
    public const COMMENT = 'Managed by Coresmith';

    private const TIMEOUT_SECONDS = 15;

    private const UNREACHABLE = "Coresmith couldn't reach Cloudflare, so nothing changed. Try again in a minute.";

    public function refusal(string $token): ?string
    {
        try {
            $verify = $this->get($token, '/user/tokens/verify');
            $zones = ($verify['result']['status'] ?? null) === 'active' ? $this->get($token, '/zones', ['per_page' => 1]) : null;
        } catch (RuntimeException) {
            $verify = null;
            $zones = null;
        }

        return match (true) {
            ($verify['result']['status'] ?? null) !== 'active' => "Cloudflare doesn't accept that token. Create one in Cloudflare under My Profile, API Tokens, from the Edit zone DNS template, then paste it here.",
            ($zones['result'] ?? []) === [] => "That token can't see any of your zones. Create one from the Edit zone DNS template, with your domain's zone under Zone Resources.",
            default => null,
        };
    }

    /**
     * @return array{id: string, name: string}|null
     */
    public function zone(string $token, string $host): ?array
    {
        $labels = explode('.', $host);

        while (count($labels) >= 2) {
            $zone = $this->get($token, '/zones', ['name' => implode('.', $labels)])['result'][0] ?? null;

            if (is_array($zone) && is_string($zone['id'] ?? null)) {
                return ['id' => $zone['id'], 'name' => (string) ($zone['name'] ?? '')];
            }

            array_shift($labels);
        }

        return null;
    }

    public function point(string $token, string $zone, string $host, string $type, string $content): void
    {
        $records = array_values(array_filter((array) ($this->get($token, "/zones/{$zone}/dns_records", ['type' => $type, 'name' => $host])['result'] ?? []), is_array(...)));
        $first = array_shift($records);
        $record = ['type' => $type, 'name' => $host, 'content' => $content, 'ttl' => 1, 'proxied' => false, 'comment' => self::COMMENT];

        if ($first === null) {
            $this->send($token, 'post', "/zones/{$zone}/dns_records", $record);
        } elseif (@inet_pton((string) ($first['content'] ?? '')) !== @inet_pton($content) || ($first['proxied'] ?? false) === true) {
            $this->send($token, 'patch', "/zones/{$zone}/dns_records/{$first['id']}", $record);
        }

        foreach ($records as $extra) {
            $this->send($token, 'delete', "/zones/{$zone}/dns_records/{$extra['id']}");
        }
    }

    /**
     * @param  array<string, scalar>  $query
     * @return array<string, mixed>
     */
    private function get(string $token, string $path, array $query = []): array
    {
        return $this->send($token, 'get', $path, $query);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function send(string $token, string $method, string $path, array $data = []): array
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
            throw new RuntimeException('Cloudflare refused the change: ' . $this->reason($response) . '.');
        }

        return (array) $response->json();
    }

    private function reason(Response $response): string
    {
        return rtrim((string) ($response->json('errors.0.message') ?? "HTTP {$response->status()}"), '.');
    }
}
