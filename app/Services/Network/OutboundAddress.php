<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Network;

use App\Enums\Edition;
use RuntimeException;

final class OutboundAddress
{
    public const PRIVATE = 'private';

    public const UNRESOLVED = 'unresolved';

    public function __construct(private readonly AddressResolver $resolver, private readonly OwnAddress $own) {}

    public function check(string $url): ?string
    {
        if (!Edition::current()->isCloud()) {
            return null;
        }

        $host = $this->host($url);

        if ($host === null) {
            return null;
        }

        $addresses = $this->addresses($host);

        if ($addresses === []) {
            return self::UNRESOLVED;
        }

        foreach ($addresses as $address) {
            if (!PublicAddress::is($address) || $this->own->is($address)) {
                return self::PRIVATE;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function options(string $url): array
    {
        if (!Edition::current()->isCloud()) {
            return [];
        }

        $refusal = $this->check($url);

        if ($refusal !== null) {
            throw new RuntimeException($refusal);
        }

        $host = (string) $this->host($url);

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return ['allow_redirects' => false];
        }

        $port = parse_url($url, PHP_URL_PORT) ?: (strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'http' ? 80 : 443);
        $pinned = implode(',', array_map(fn(string $address): string => str_contains($address, ':') ? "[{$address}]" : $address, $this->addresses($host)));

        return ['allow_redirects' => false, 'curl' => [CURLOPT_RESOLVE => ["{$host}:{$port}:{$pinned}"]]];
    }

    private function host(string $url): ?string
    {
        $host = parse_url(trim($url), PHP_URL_HOST);

        return is_string($host) && $host !== '' ? strtolower(trim($host, '[]')) : null;
    }

    /**
     * @return list<string>
     */
    private function addresses(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        return array_values(array_filter($this->resolver->addresses($host), fn(string $address): bool => filter_var($address, FILTER_VALIDATE_IP) !== false));
    }
}
