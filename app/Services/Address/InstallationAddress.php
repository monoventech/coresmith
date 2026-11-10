<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Address;

use App\Models\TeamDomain;
use App\Services\Network\AddressResolver;
use App\Services\Network\LocalAddresses;
use App\Services\SafeName;
use Illuminate\Support\Str;

final class InstallationAddress
{
    private const INTERNAL_SUFFIXES = ['.localhost', '.local', '.internal', '.home.arpa'];

    public function __construct(
        private readonly AddressResolver $resolver,
        private readonly LocalAddresses $local,
    ) {}

    public static function normalise(string $input): string
    {
        $value = trim($input);
        $parts = parse_url(str_contains($value, '://') ? $value : "https://{$value}");
        $host = is_array($parts) ? (string) ($parts['host'] ?? '') : '';

        return rtrim(strtolower(trim($host, '[]')), '.');
    }

    public static function urlHost(string $host): string
    {
        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? "[{$host}]" : $host;
    }

    public static function certificate(string $host): CertificateSource
    {
        return match (true) {
            str_ends_with($host, '.ts.net') => CertificateSource::Tailscale,
            $host === 'localhost', filter_var($host, FILTER_VALIDATE_IP) !== false, Str::endsWith($host, self::INTERNAL_SUFFIXES) => CertificateSource::Internal,
            default => CertificateSource::Public,
        };
    }

    public function host(): string
    {
        return self::normalise((string) config('app.url'));
    }

    public function managed(): bool
    {
        return (string) config('coresmith.address.site') !== '';
    }

    public function refusal(string $host): ?string
    {
        return $this->formRefusal($host) ?? (filter_var($host, FILTER_VALIDATE_IP) !== false ? null : $this->dnsRefusal($host));
    }

    public function formRefusal(string $host): ?string
    {
        if ($host === '') {
            return 'Enter the address Coresmith should answer on, like coresmith.example.com.';
        }

        if ($host === $this->host()) {
            return "That is already Coresmith's address.";
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return $this->isLocal([$host]) ? null : "{$host} isn't one of this server's addresses.";
        }

        if (!SafeName::isDomain($host) || !str_contains($host, '.') || str_contains($host, '*')) {
            return "That doesn't look like a domain. Try coresmith.example.com.";
        }

        if (TeamDomain::query()->where('hostname', $host)->exists()) {
            return "A team already opens on {$host}. Remove it from that team first.";
        }

        return null;
    }

    public function dnsRefusal(string $host): ?string
    {
        $addresses = array_values(array_filter($this->resolver->addresses($host), fn(string $address): bool => filter_var($address, FILTER_VALIDATE_IP) !== false));

        if ($addresses === []) {
            return "{$host} doesn't point anywhere yet. Add a DNS record that points it at this server, then check again.";
        }

        if ($this->isLocal($addresses) || array_filter($addresses, fn(string $address): bool => filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) !== false) !== []) {
            return null;
        }

        return "{$host} points at {$addresses[0]}, which isn't this server. Point it at this server, then check again.";
    }

    /**
     * @param  list<string>  $addresses
     */
    private function isLocal(array $addresses): bool
    {
        $local = array_map(inet_pton(...), $this->local->all());

        foreach ($addresses as $address) {
            if (in_array(inet_pton($address), $local, true)) {
                return true;
            }
        }

        return false;
    }
}
