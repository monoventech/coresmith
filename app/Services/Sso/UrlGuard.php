<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Sso;

use App\Services\Network\AddressResolver;
use App\Services\SafeName;

final class UrlGuard
{
    /** @var list<string> */
    public const BLOCKED_HOSTS = ['localhost', 'metadata.google.internal', 'metadata.goog'];

    /** @var list<string> */
    public const BLOCKED_RANGES = [
        '0.0.0.0/8',
        '127.0.0.0/8',
        '169.254.0.0/16',
        '100.100.100.200/32',
        '::/128',
        '::1/128',
        'fe80::/10',
        'fc00::/7',
    ];

    public function __construct(private readonly AddressResolver $resolver) {}

    public function issuerRefusal(string $url): ?string
    {
        $parts = parse_url(trim($url));

        if (is_array($parts) && (isset($parts['query']) || isset($parts['fragment']))) {
            return 'Remove the ? query or # fragment from the issuer URL.';
        }

        return $this->refusal($url);
    }

    public function refusal(string $url): ?string
    {
        try {
            $this->inspect($url, false);

            return null;
        } catch (SsoException $e) {
            return $e->getMessage();
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function options(string $url): array
    {
        [$host, $port, $addresses] = $this->inspect($url, true);

        if (filter_var($host, FILTER_VALIDATE_IP) !== false || !defined('CURLOPT_RESOLVE')) {
            return [];
        }

        $pinned = implode(',', array_map(
            fn(string $address): string => str_contains($address, ':') ? '[' . $address . ']' : $address,
            $addresses,
        ));

        return ['curl' => [CURLOPT_RESOLVE => [$host . ':' . $port . ':' . $pinned]]];
    }

    /**
     * @return array{0: string, 1: int, 2: list<string>}
     */
    private function inspect(string $url, bool $mustResolve): array
    {
        $url = trim($url);
        $parts = preg_match('/[\x00-\x20\x7f\\\\]/', $url) === 1 ? false : parse_url($url);

        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || ($parts['host'] ?? '') === '') {
            throw new SsoException('Use an https:// address, like https://login.example.com.', 'url.scheme');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new SsoException('Remove the username and password from the address.', 'url.credentials');
        }

        if (isset($parts['fragment'])) {
            throw new SsoException('Remove the # fragment from the address.', 'url.fragment');
        }

        $host = rtrim(strtolower(trim($parts['host'], '[]')), '.');
        $port = (int) ($parts['port'] ?? 443);

        if (in_array($host, self::BLOCKED_HOSTS, true) || str_ends_with($host, '.localhost')) {
            throw new SsoException("Coresmith won't connect to {$host}. Use your identity provider's public address.", 'url.blocked');
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $this->assertAllowed($host, $host);

            return [$host, $port, [$host]];
        }

        if (!SafeName::isDomain($host) || str_starts_with($host, '*.')) {
            throw new SsoException("\"{$host}\" is not a valid host name.", 'url.host');
        }

        $addresses = array_values(array_filter($this->resolver->addresses($host), fn(string $address): bool => filter_var($address, FILTER_VALIDATE_IP) !== false));

        foreach ($addresses as $address) {
            $this->assertAllowed($address, $host);
        }

        if ($mustResolve && $addresses === []) {
            throw new SsoException("Coresmith could not find an address for {$host}.", 'url.unresolved');
        }

        return [$host, $port, $addresses];
    }

    private function assertAllowed(string $address, string $host): void
    {
        foreach (self::BLOCKED_RANGES as $range) {
            if (self::inRange($address, $range) || self::inRange((string) self::embeddedIpv4($address), $range)) {
                throw new SsoException("Coresmith won't connect to {$host}, because it points at a private address.", 'url.blocked');
            }
        }
    }

    private static function embeddedIpv4(string $address): ?string
    {
        $packed = @inet_pton($address);

        if ($packed === false || strlen($packed) !== 16 || substr($packed, 0, 10) !== str_repeat("\0", 10)) {
            return null;
        }

        $marker = substr($packed, 10, 2);

        if ($marker !== "\xff\xff" && $marker !== "\0\0") {
            return null;
        }

        return inet_ntop(substr($packed, 12)) ?: null;
    }

    private static function inRange(string $ip, string $range): bool
    {
        [$subnet, $bits] = explode('/', $range);

        $packedIp = @inet_pton($ip);
        $packedSubnet = @inet_pton($subnet);

        if ($packedIp === false || $packedSubnet === false || strlen($packedIp) !== strlen($packedSubnet)) {
            return false;
        }

        $bytes = intdiv((int) $bits, 8);
        $remainder = (int) $bits % 8;

        if ($bytes > 0 && strncmp($packedIp, $packedSubnet, $bytes) !== 0) {
            return false;
        }

        if ($remainder === 0) {
            return true;
        }

        $mask = ~((1 << (8 - $remainder)) - 1) & 0xFF;

        return (ord($packedIp[$bytes]) & $mask) === (ord($packedSubnet[$bytes]) & $mask);
    }
}
