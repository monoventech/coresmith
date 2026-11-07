<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Billing;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\UnexpectedValueException;
use Stripe\HttpClient\ClientInterface;
use Stripe\Stripe;
use Stripe\Util\CaseInsensitiveArray;
use Stripe\Util\Util;

final class StripeHttpClient implements ClientInterface
{
    private const TIMEOUT_SECONDS = 30;

    private const CONNECT_TIMEOUT_SECONDS = 10;

    /**
     * @return array{0: string, 1: int, 2: CaseInsensitiveArray}
     */
    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null): array
    {
        if ($hasFile || $apiMode !== 'v1') {
            throw new UnexpectedValueException('Coresmith only sends Stripe v1 requests without files.');
        }

        $method = strtolower((string) $method);
        $encoded = Util::encodeParameters(Util::objectsToIds((array) $params, false));
        $url = $method !== 'post' && $encoded !== '' ? $absUrl . '?' . $encoded : $absUrl;
        $headers = $this->headers((array) $headers);
        $retries = $maxNetworkRetries ?? Stripe::getMaxNetworkRetries();

        if ($method === 'post' && $retries > 0 && !isset($headers['Idempotency-Key'])) {
            $headers['Idempotency-Key'] = (string) Str::uuid();
        }

        for ($attempt = 0; ; $attempt++) {
            try {
                $pending = Http::withHeaders($headers)->timeout(self::TIMEOUT_SECONDS)->connectTimeout(self::CONNECT_TIMEOUT_SECONDS);
                $response = $method === 'post'
                    ? $pending->withBody($encoded, 'application/x-www-form-urlencoded')->send('POST', $url)
                    : $pending->send(strtoupper($method), $url);
            } catch (ConnectionException $exception) {
                if ($attempt < $retries) {
                    $this->pause($attempt);

                    continue;
                }

                throw ApiConnectionException::factory('Could not reach Stripe: ' . $exception->getMessage());
            }

            if ($attempt < $retries && $this->shouldRetry($response)) {
                $this->pause($attempt);

                continue;
            }

            return [$response->body(), $response->status(), new CaseInsensitiveArray(array_map(fn(array $values): string => (string) ($values[0] ?? ''), $response->headers()))];
        }
    }

    /**
     * @param  array<int|string, string>  $headers
     * @return array<string, string>
     */
    private function headers(array $headers): array
    {
        $split = [];

        foreach ($headers as $header) {
            [$name, $value] = array_pad(explode(':', (string) $header, 2), 2, '');
            $split[trim($name)] = trim($value);
        }

        return $split;
    }

    private function shouldRetry(Response $response): bool
    {
        return match (strtolower((string) $response->header('Stripe-Should-Retry'))) {
            'false' => false,
            'true' => true,
            default => $response->status() === 409 || $response->status() >= 500,
        };
    }

    private function pause(int $attempt): void
    {
        Sleep::for(min(500 * 2 ** $attempt, 8000))->milliseconds();
    }
}
