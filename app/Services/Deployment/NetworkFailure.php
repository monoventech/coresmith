<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Deployment;

final class NetworkFailure
{
    private const STATUSES = [502 => '502 Bad Gateway', 503 => '503 Service Unavailable', 504 => '504 Gateway Timeout'];

    private const ANSWERED = ['/\b(50[234])\s+(?:Bad Gateway|Service Unavailable|Gateway Time-?out)\b/i', '/HTTP\/[\d.]+\s+(50[234])\b/'];

    private const UNRESOLVED = '/ENOTFOUND|EAI_AGAIN|Could not resolve host|curl error 6\b|Temporary failure in name resolution|Name or service not known/';

    private const UNREACHABLE = '/ETIMEDOUT|ECONNRESET|ECONNREFUSED|Connection timed out|Operation timed out|curl error 28\b|socket hang up|Net::OpenTimeout|Connection refused|Connection reset|Failed to connect/';

    private const RECOVERED = '/\bwarn(?:ing)?\b|\bretr(?:y|ying|ies)\b/i';

    private const URL = '~\bhttps?://(\[[0-9a-f:.]+\]|[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?)~i';

    public static function reason(string $output, int $attempts): ?string
    {
        $line = self::failingLine($output);

        if ($line === null) {
            return null;
        }

        $host = self::host($line);

        if ($host === null) {
            return null;
        }

        $status = self::status($line);

        return match (true) {
            $status !== null => "The trouble is at {$host}, which answered {$status}" . self::times($attempts) . '. Deploy again in a few minutes.',
            preg_match(self::UNRESOLVED, $line) === 1 => self::tried($attempts) . " couldn't look up {$host}. Deploy again in a few minutes, and if it still fails, check the server's DNS settings.",
            default => self::tried($attempts) . " couldn't reach {$host}. Deploy again in a few minutes, and if it still fails, check the server's firewall allows outbound connections.",
        };
    }

    private static function failingLine(string $output): ?string
    {
        foreach (array_reverse(preg_split('/\R/', $output) ?: []) as $line) {
            if (preg_match(self::RECOVERED, $line) === 1) {
                continue;
            }

            if (self::status($line) !== null || preg_match(self::UNRESOLVED, $line) === 1 || preg_match(self::UNREACHABLE, $line) === 1) {
                return $line;
            }
        }

        return null;
    }

    private static function status(string $line): ?string
    {
        foreach (self::ANSWERED as $pattern) {
            if (preg_match($pattern, $line, $match) === 1) {
                return self::STATUSES[(int) $match[1]];
            }
        }

        return null;
    }

    private static function host(string $line): ?string
    {
        if (preg_match(self::URL, $line, $match) !== 1) {
            return null;
        }

        $host = strtolower(trim($match[1], '[]'));

        return self::loopback($host) ? null : $host;
    }

    private static function loopback(string $host): bool
    {
        if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
            return true;
        }

        return filter_var($host, FILTER_VALIDATE_IP) !== false && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_RES_RANGE) === false;
    }

    private static function times(int $attempts): string
    {
        return match (true) {
            $attempts === 2 => ' both times',
            $attempts > 2 => " all {$attempts} times",
            default => '',
        };
    }

    private static function tried(int $attempts): string
    {
        return match (true) {
            $attempts === 2 => 'The server tried twice and',
            $attempts > 2 => "The server tried {$attempts} times and",
            default => 'The server',
        };
    }
}
