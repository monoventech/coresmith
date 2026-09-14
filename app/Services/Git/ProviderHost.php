<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Git;

use App\Exceptions\GitProviderException;
use App\Services\SafeName;

final class ProviderHost
{
    /** @var list<string> */
    public const BLOCKED_HOSTS = ['localhost', 'metadata.google.internal', 'metadata.goog'];

    /** @var list<string> */
    public const BLOCKED_RANGES = ['127.0.0.0/8', '169.254.0.0/16', '::1/128', 'fe80::/10', 'fd00::/8'];

    public static function normalise(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        $parts = parse_url($value);

        if ($parts === false || ($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') === '') {
            throw new GitProviderException('A self-hosted provider must be given as an https:// URL.');
        }

        self::assertReachableTarget((string) $parts['host']);

        return 'https://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    public static function isValid(?string $value): bool
    {
        try {
            self::normalise($value);

            return true;
        } catch (GitProviderException) {
            return false;
        }
    }

    private static function assertReachableTarget(string $host): void
    {
        $host = rtrim(strtolower(trim($host, '[]')), '.');

        if (in_array($host, self::BLOCKED_HOSTS, true)) {
            throw new GitProviderException("Coresmith will not talk to {$host}.");
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            self::assertAddressAllowed($host, $host);

            return;
        }

        if (!SafeName::isDomain($host)) {
            throw new GitProviderException("\"{$host}\" is not a valid host name.");
        }

        foreach (self::resolve($host) as $address) {
            self::assertAddressAllowed($address, $host);
        }
    }

    private static function assertAddressAllowed(string $address, string $host): void
    {
        foreach (self::BLOCKED_RANGES as $range) {
            if (self::inRange($address, $range)) {
                throw new GitProviderException("Coresmith will not talk to {$host}: it resolves to {$address}.");
            }
        }
    }

    /**
     * @return list<string>
     */
    private static function resolve(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);

        if ($records === false) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn(array $record): string => (string) ($record['ip'] ?? $record['ipv6'] ?? ''),
            $records,
        )));
    }

    private static function inRange(string $ip, string $range): bool
    {
        [$subnet, $bits] = explode('/', $range);

        $packedIp = inet_pton($ip);
        $packedSubnet = inet_pton($subnet);

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
