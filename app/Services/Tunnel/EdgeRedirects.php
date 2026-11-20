<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Tunnel;

use App\Services\Network\CloudflareRanges;
use App\Services\SSH\SSHConnection;
use Illuminate\Support\Facades\Http;
use Throwable;

final class EdgeRedirects
{
    public const SECONDS = 8;

    public const AGENT = 'Coresmith-tunnel-check';

    private const RESOLVER = 'https://cloudflare-dns.com/dns-query';

    /**
     * @param  list<string>  $hosts
     * @return list<array{host: string, to: string}>
     */
    public function find(SSHConnection $connection, array $hosts): array
    {
        $diverted = [];

        foreach ($hosts as $host) {
            $edge = $this->edge($host);

            if ($edge === null || !self::elsewhere($host, $edge)) {
                continue;
            }

            if ($this->origin($connection, $host) === $edge) {
                continue;
            }

            $diverted[] = ['host' => $host, 'to' => $edge];
        }

        return $diverted;
    }

    private function edge(string $host): ?string
    {
        foreach (['A' => 1, 'AAAA' => 28] as $type => $code) {
            $address = $this->cloudflareAddress($host, $type, $code);

            if ($address === null) {
                continue;
            }

            try {
                $response = Http::withOptions(['allow_redirects' => false, 'curl' => [CURLOPT_RESOLVE => ["{$host}:443:" . ($code === 28 ? "[{$address}]" : $address)]]])
                    ->withUserAgent(self::AGENT)
                    ->timeout(self::SECONDS)
                    ->get("https://{$host}/");
            } catch (Throwable) {
                continue;
            }

            return $response->redirect() ? self::hostOf($response->header('Location')) : null;
        }

        return null;
    }

    private function cloudflareAddress(string $host, string $type, int $code): ?string
    {
        try {
            $answers = Http::withHeaders(['Accept' => 'application/dns-json'])
                ->timeout(self::SECONDS)
                ->get(self::RESOLVER, ['name' => $host, 'type' => $type])
                ->json('Answer');
        } catch (Throwable) {
            return null;
        }

        foreach (is_array($answers) ? $answers : [] as $answer) {
            $data = is_array($answer) ? ($answer['data'] ?? null) : null;

            if (($answer['type'] ?? null) === $code && is_string($data) && CloudflareRanges::containsAll([$data])) {
                return $data;
            }
        }

        return null;
    }

    private function origin(SSHConnection $connection, string $host): ?string
    {
        $command = 'curl -sk -o /dev/null --max-time ' . self::SECONDS
            . ' -A ' . escapeshellarg(self::AGENT)
            . ' -w ' . escapeshellarg('%{redirect_url}')
            . ' --resolve ' . escapeshellarg("{$host}:443:127.0.0.1")
            . ' ' . escapeshellarg("https://{$host}/");

        try {
            return self::hostOf($connection->execute($command, timeout: self::SECONDS + 5)->output);
        } catch (Throwable) {
            return null;
        }
    }

    private static function elsewhere(string $host, string $to): bool
    {
        $bare = fn(string $name): string => str_starts_with($name, 'www.') ? substr($name, 4) : $name;

        return $bare($to) !== $bare(strtolower($host));
    }

    private static function hostOf(string $url): ?string
    {
        $host = parse_url(trim($url), PHP_URL_HOST);

        return is_string($host) && $host !== '' ? strtolower($host) : null;
    }
}
