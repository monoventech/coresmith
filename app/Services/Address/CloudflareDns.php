<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Address;

use App\Exceptions\CloudflareRefused;
use RuntimeException;
use SensitiveParameter;

final class CloudflareDns
{
    public const COMMENT = 'Managed by Coresmith';

    public function __construct(private readonly CloudflareApi $api) {}

    public function refusal(#[SensitiveParameter] string $token): ?string
    {
        try {
            $status = $this->status($token);
            $zones = $status === 'active' ? $this->api->get($token, '/zones', ['per_page' => 1]) : null;
        } catch (RuntimeException) {
            $status = null;
            $zones = null;
        }

        return match (true) {
            $status !== 'active' => "Cloudflare doesn't accept that token. Paste the whole token Cloudflare showed you, or create a new one.",
            ($zones['result'] ?? []) === [] => "That token can't see any of your zones. In Cloudflare, add your domain's zone under the token's Zone Resources, then try again.",
            default => null,
        };
    }

    private function status(#[SensitiveParameter] string $token): ?string
    {
        try {
            $verified = $this->api->get($token, '/user/tokens/verify');
        } catch (CloudflareRefused $refused) {
            $account = $this->account($token) ?? throw $refused;
            $verified = $this->api->get($token, "/accounts/{$account}/tokens/verify");
        }

        $status = $verified['result']['status'] ?? null;

        return is_string($status) ? $status : null;
    }

    private function account(#[SensitiveParameter] string $token): ?string
    {
        $account = rescue(fn(): mixed => $this->api->get($token, '/zones', ['per_page' => 1])['result'][0]['account']['id'] ?? null, report: false)
            ?: rescue(fn(): mixed => $this->api->get($token, '/accounts', ['per_page' => 1])['result'][0]['id'] ?? null, report: false);

        return is_string($account) && $account !== '' ? $account : null;
    }

    /**
     * @return array{id: string, name: string, account: string}|null
     */
    public function zone(#[SensitiveParameter] string $token, string $host): ?array
    {
        $labels = explode('.', $host);

        while (count($labels) >= 2) {
            $zone = $this->api->get($token, '/zones', ['name' => implode('.', $labels)])['result'][0] ?? null;

            if (is_array($zone) && is_string($zone['id'] ?? null)) {
                return ['id' => $zone['id'], 'name' => (string) ($zone['name'] ?? ''), 'account' => (string) ($zone['account']['id'] ?? '')];
            }

            array_shift($labels);
        }

        return null;
    }

    public function point(#[SensitiveParameter] string $token, string $zone, string $host, string $type, string $content): void
    {
        $records = array_values(array_filter((array) ($this->api->get($token, "/zones/{$zone}/dns_records", ['type' => $type, 'name' => $host])['result'] ?? []), is_array(...)));
        $first = array_shift($records);
        $record = ['type' => $type, 'name' => $host, 'content' => $content, 'ttl' => 1, 'proxied' => false, 'comment' => self::COMMENT];

        if ($first === null) {
            $this->api->send($token, 'post', "/zones/{$zone}/dns_records", $record);
        } elseif (@inet_pton((string) ($first['content'] ?? '')) !== @inet_pton($content)) {
            $this->api->send($token, 'patch', "/zones/{$zone}/dns_records/{$first['id']}", [...$record, 'proxied' => ($first['proxied'] ?? false) === true]);
        }

        foreach ($records as $extra) {
            $this->api->send($token, 'delete', "/zones/{$zone}/dns_records/{$extra['id']}");
        }
    }

    /**
     * @param  list<string>  $own
     */
    public function routeToTunnel(#[SensitiveParameter] string $token, string $zone, string $host, string $target, array $own, bool $create = true): ?string
    {
        $records = $this->records($token, $zone, $host);
        $alias = array_values(array_filter($records, fn(array $record): bool => ($record['type'] ?? null) === 'CNAME'))[0] ?? null;

        if ($alias !== null) {
            if (self::sameName((string) ($alias['content'] ?? ''), $target)) {
                if (($alias['proxied'] ?? false) !== true) {
                    $this->api->send($token, 'patch', "/zones/{$zone}/dns_records/{$alias['id']}", ['proxied' => true]);
                }

                return null;
            }

            return "{$host} points at {$alias['content']}, so Coresmith left it alone.";
        }

        $addresses = array_values(array_filter($records, fn(array $record): bool => in_array($record['type'] ?? null, ['A', 'AAAA'], true)));

        foreach ($addresses as $record) {
            if (!self::listed((string) ($record['content'] ?? ''), $own)) {
                return "{$host} points at {$record['content']}, which isn't this server, so Coresmith left it alone.";
            }
        }

        if ($addresses === [] && !$create) {
            return '';
        }

        foreach ($addresses as $record) {
            $this->api->send($token, 'delete', "/zones/{$zone}/dns_records/{$record['id']}");
        }

        try {
            $this->api->send($token, 'post', "/zones/{$zone}/dns_records", ['type' => 'CNAME', 'name' => $host, 'content' => $target, 'ttl' => 1, 'proxied' => true, 'comment' => self::COMMENT]);
        } catch (RuntimeException $refused) {
            foreach ($addresses as $record) {
                rescue(fn() => $this->api->send($token, 'post', "/zones/{$zone}/dns_records", [
                    'type' => $record['type'],
                    'name' => $host,
                    'content' => $record['content'],
                    'ttl' => $record['ttl'] ?? 1,
                    'proxied' => ($record['proxied'] ?? false) === true,
                    'comment' => $record['comment'] ?? self::COMMENT,
                ]), report: false);
            }

            throw $refused;
        }

        return null;
    }

    /**
     * @param  list<array{type: string, value: string}>  $restore
     */
    public function routeAway(#[SensitiveParameter] string $token, string $zone, string $host, string $target, array $restore): void
    {
        foreach ($this->records($token, $zone, $host, 'CNAME') as $record) {
            if (($record['type'] ?? null) !== 'CNAME' || !self::sameName((string) ($record['content'] ?? ''), $target)) {
                continue;
            }

            $this->api->send($token, 'delete', "/zones/{$zone}/dns_records/{$record['id']}");

            foreach ($restore as $address) {
                $this->api->send($token, 'post', "/zones/{$zone}/dns_records", ['type' => $address['type'], 'name' => $host, 'content' => $address['value'], 'ttl' => 1, 'proxied' => false, 'comment' => self::COMMENT]);
            }
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function records(#[SensitiveParameter] string $token, string $zone, string $host, ?string $type = null): array
    {
        $query = [...($type === null ? [] : ['type' => $type]), 'name' => $host, 'per_page' => 100];

        return array_values(array_filter((array) ($this->api->get($token, "/zones/{$zone}/dns_records", $query)['result'] ?? []), is_array(...)));
    }

    /**
     * @param  list<string>  $own
     */
    private static function listed(string $address, array $own): bool
    {
        $packed = @inet_pton($address);

        return $packed !== false && array_filter($own, fn(string $mine): bool => @inet_pton($mine) === $packed) !== [];
    }

    private static function sameName(string $one, string $other): bool
    {
        return strtolower(rtrim($one, '.')) === strtolower(rtrim($other, '.'));
    }
}
