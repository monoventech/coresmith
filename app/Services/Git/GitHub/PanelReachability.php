<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Git\GitHub;

use App\Services\Sso\AddressResolver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

final class PanelReachability
{
    public const CACHE_MINUTES = 10;

    /** @var list<string> */
    private const LOCAL_SUFFIXES = ['.local', '.lan', '.home', '.home.arpa', '.internal', '.localdomain', '.test', '.invalid', '.example'];

    public function __construct(private readonly AddressResolver $resolver) {}

    public function verdict(?string $url = null): Reachability
    {
        $url ??= (string) config('app.url');
        $key = 'panel-reachability:' . sha1($url);
        $cached = Cache::get($key);
        $remembered = is_array($cached) ? Reachability::fromArray($cached) : null;

        if ($remembered !== null) {
            return $remembered;
        }

        $verdict = $this->classify($url);

        Cache::put($key, $verdict->toArray(), now()->addMinutes(self::CACHE_MINUTES));

        return $verdict;
    }

    private function classify(string $url): Reachability
    {
        $url = trim($url);
        $parts = preg_match('/[\x00-\x20\x7f\\\\]/', $url) === 1 ? false : parse_url($url);
        $parts = is_array($parts) ? $parts : [];
        $host = $this->host((string) ($parts['host'] ?? ''));

        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost')) {
            return new Reachability(false, $host, Reachability::LOOPBACK, null);
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return $this->literal($host);
        }

        if (!str_contains($host, '.') || Str::endsWith('.' . $host, self::LOCAL_SUFFIXES)) {
            return new Reachability(false, $host, Reachability::LOCAL, null);
        }

        $addresses = collect($this->resolver->addresses($host))
            ->filter(fn(string $address): bool => filter_var($address, FILTER_VALIDATE_IP) !== false)
            ->values();

        if ($addresses->isNotEmpty() && $addresses->every(fn(string $address): bool => $this->loopback($address))) {
            return new Reachability(false, $host, Reachability::LOOPBACK, null);
        }

        if ($addresses->isNotEmpty() && $addresses->every(fn(string $address): bool => !$this->global($address))) {
            return new Reachability(false, $host, Reachability::PRIVATE, $addresses->first());
        }

        if (strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            return new Reachability(false, $host, Reachability::HTTP, null);
        }

        return new Reachability(true, $host, null, null);
    }

    private function host(string $host): string
    {
        $host = rtrim(strtolower(trim($host, '[]')), '.');

        return preg_match('/[^a-z0-9._:-]/', $host) === 1 ? '' : $host;
    }

    private function literal(string $ip): Reachability
    {
        return match (true) {
            $this->loopback($ip) => new Reachability(false, $ip, Reachability::LOOPBACK, null),
            !$this->global($ip) => new Reachability(false, $ip, Reachability::PRIVATE, $ip),
            default => new Reachability(false, $ip, Reachability::IP, null),
        };
    }

    private function loopback(string $ip): bool
    {
        $packed = inet_pton($ip);

        if ($packed === false) {
            return false;
        }

        return strlen($packed) === 4 ? $packed[0] === "\x7f" : $packed === inet_pton('::1');
    }

    private function global(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) !== false;
    }
}
