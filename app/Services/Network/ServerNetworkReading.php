<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Network;

final readonly class ServerNetworkReading implements LocalAddresses, StableAddresses
{
    /**
     * @param  list<string>  $stable
     * @param  list<string>  $addresses
     */
    public function __construct(
        public array $stable,
        public array $addresses,
        public ?DirectReach $direct,
    ) {}

    public static function parse(string $output): self
    {
        $sections = [];
        $current = null;

        foreach (preg_split('/\r\n|\n|\r/', $output) ?: [] as $line) {
            if (preg_match('/^== (\w+)$/', trim($line), $match) === 1) {
                $current = $match[1];
                $sections[$current] = [];
            } elseif ($current !== null) {
                $sections[$current][] = $line;
            }
        }

        $addresses = [];

        foreach ($sections['addresses'] ?? [] as $line) {
            $address = explode('/', trim($line))[0];

            if (filter_var($address, FILTER_VALIDATE_IP) !== false) {
                $addresses[] = $address;
            }
        }

        return new self(
            ProcStableAddresses::parse(implode("\n", $sections['inet6'] ?? [])),
            array_values(array_unique($addresses)),
            self::direct(trim(implode("\n", $sections['direct'] ?? []))),
        );
    }

    public function ipv6(): array
    {
        return $this->stable;
    }

    public function all(): array
    {
        return $this->addresses;
    }

    private static function direct(string $body): ?DirectReach
    {
        $json = json_decode($body, true);

        if (!is_array($json) || !is_string($json['address'] ?? null) || filter_var($json['address'], FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $ports = is_array($json['ports'] ?? null) ? $json['ports'] : [];

        return new DirectReach($json['address'], ($ports['80'] ?? false) === true, ($ports['443'] ?? false) === true);
    }
}
