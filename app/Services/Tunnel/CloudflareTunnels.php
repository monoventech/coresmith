<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Tunnel;

use App\Exceptions\CloudflareRefused;
use App\Services\Address\CloudflareApi;
use RuntimeException;
use SensitiveParameter;

final class CloudflareTunnels
{
    public const NO_TUNNEL_PERMISSION = "That Cloudflare token can't manage tunnels. In Cloudflare, edit the token, add Account, Cloudflare Tunnel, Edit, then try again.";

    public function __construct(private readonly CloudflareApi $api) {}

    public function account(#[SensitiveParameter] string $token): ?string
    {
        $id = $this->api->get($token, '/accounts', ['per_page' => 5])['result'][0]['id'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    public function refusal(#[SensitiveParameter] string $token, string $account): ?string
    {
        try {
            $this->api->get($token, "/accounts/{$account}/cfd_tunnel", ['per_page' => 1, 'is_deleted' => 'false']);
        } catch (CloudflareRefused) {
            return self::NO_TUNNEL_PERMISSION;
        }

        return null;
    }

    public function ensure(#[SensitiveParameter] string $token, string $account, string $name): string
    {
        $found = $this->api->get($token, "/accounts/{$account}/cfd_tunnel", ['name' => $name, 'is_deleted' => 'false'])['result'][0]['id'] ?? null;

        if (is_string($found) && $found !== '') {
            return $found;
        }

        $id = $this->api->send($token, 'post', "/accounts/{$account}/cfd_tunnel", ['name' => $name, 'config_src' => 'cloudflare'])['result']['id'] ?? null;

        if (!is_string($id) || $id === '') {
            throw new RuntimeException("Cloudflare made the tunnel but didn't say what it's called. Try again.");
        }

        return $id;
    }

    public function runToken(#[SensitiveParameter] string $token, string $account, string $tunnel): string
    {
        $run = $this->api->get($token, "/accounts/{$account}/cfd_tunnel/{$tunnel}/token")['result'] ?? null;

        if (!is_string($run) || $run === '') {
            throw new RuntimeException("Cloudflare didn't send the tunnel's token. Try again.");
        }

        return $run;
    }

    /**
     * @param  list<string>  $hosts
     */
    public function configure(#[SensitiveParameter] string $token, string $account, string $tunnel, array $hosts): void
    {
        $this->api->send($token, 'put', "/accounts/{$account}/cfd_tunnel/{$tunnel}/configurations", ['config' => ['ingress' => self::ingress($hosts)]]);
    }

    public function status(#[SensitiveParameter] string $token, string $account, string $tunnel): string
    {
        return (string) ($this->api->get($token, "/accounts/{$account}/cfd_tunnel/{$tunnel}")['result']['status'] ?? '');
    }

    public function delete(#[SensitiveParameter] string $token, string $account, string $tunnel): void
    {
        rescue(fn() => $this->api->send($token, 'delete', "/accounts/{$account}/cfd_tunnel/{$tunnel}/connections"), report: false);

        $this->api->send($token, 'delete', "/accounts/{$account}/cfd_tunnel/{$tunnel}");
    }

    /**
     * @param  list<string>  $hosts
     * @return list<array<string, mixed>>
     */
    public static function ingress(array $hosts): array
    {
        $rules = [];

        foreach ($hosts as $host) {
            $rules[] = ['hostname' => $host, 'path' => '^/\.well-known/acme-challenge/', 'service' => 'http://127.0.0.1:80'];
            $rules[] = ['hostname' => $host, 'service' => 'https://127.0.0.1:443', 'originRequest' => ['originServerName' => $host, 'noTLSVerify' => true]];
        }

        return [...$rules, ['service' => 'http_status:404']];
    }
}
