<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Support\Sentry;

final class BrowserSentry
{
    private const HOST = '~^[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?)*\z~';

    private const PROJECT = '~^/[0-9]+\z~';

    private const NOT_ALLOWED = ['pass' => true, 'query' => true, 'fragment' => true];

    /**
     * @return array{dsn: string, environment: string, release: string, tracesSampleRate: float}|null
     */
    public static function settings(): ?array
    {
        $dsn = self::dsn();

        if ($dsn === null) {
            return null;
        }

        return [
            'dsn' => $dsn['address'],
            'environment' => (string) (config('sentry.environment') ?: app()->environment()),
            'release' => (string) config('sentry.release'),
            'tracesSampleRate' => self::tracesSampleRate(),
        ];
    }

    public static function origin(): ?string
    {
        return self::dsn()['origin'] ?? null;
    }

    /**
     * @return array{address: string, origin: string}|null
     */
    private static function dsn(): ?array
    {
        $address = config('coresmith.sentry.browser_dsn');

        if (!is_string($address) || !ctype_graph($address)) {
            return null;
        }

        $parts = parse_url($address);

        if (!is_array($parts) || !self::valid($parts)) {
            return null;
        }

        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        return ['address' => $address, 'origin' => $parts['scheme'] . '://' . $parts['host'] . $port];
    }

    /**
     * @param  array<string, int|string>  $parts
     */
    private static function valid(array $parts): bool
    {
        $host = (string) ($parts['host'] ?? '');

        return in_array($parts['scheme'] ?? null, ['http', 'https'], true)
            && strlen($host) <= 253
            && preg_match(self::HOST, $host) === 1
            && ($parts['user'] ?? '') !== ''
            && array_intersect_key($parts, self::NOT_ALLOWED) === []
            && ($parts['port'] ?? 1) >= 1
            && preg_match(self::PROJECT, (string) ($parts['path'] ?? '')) === 1;
    }

    private static function tracesSampleRate(): float
    {
        $rate = (float) config('coresmith.sentry.browser_traces_sample_rate');

        return is_finite($rate) ? min(1.0, max(0.0, $rate)) : 0.0;
    }
}
