<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Network;

final class ProcStableAddresses implements StableAddresses
{
    public const PATH = '/proc/net/if_inet6';

    private const GLOBAL_SCOPE = '00';

    private const UNSTABLE = 0x01 | 0x08 | 0x20 | 0x40;

    public function __construct(private readonly string $path = self::PATH) {}

    public function ipv6(): array
    {
        $contents = @file_get_contents($this->path);

        return self::parse($contents === false ? '' : $contents);
    }

    /**
     * @return list<string>
     */
    public static function parse(string $contents): array
    {
        $addresses = [];

        foreach (preg_split('/\r\n|\n|\r/', $contents) ?: [] as $line) {
            $fields = preg_split('/\s+/', trim($line)) ?: [];

            if (count($fields) < 6 || $fields[3] !== self::GLOBAL_SCOPE || (hexdec($fields[4]) & self::UNSTABLE) !== 0) {
                continue;
            }

            $packed = @inet_pton(implode(':', str_split($fields[0], 4)));
            $address = $packed === false ? false : inet_ntop($packed);

            if ($address !== false && filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 | FILTER_FLAG_GLOBAL_RANGE) !== false) {
                $addresses[] = $address;
            }
        }

        return array_values(array_unique($addresses));
    }
}
