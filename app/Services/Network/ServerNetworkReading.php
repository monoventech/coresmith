<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Network;

use Symfony\Component\HttpFoundation\IpUtils;

final readonly class ServerNetworkReading implements LocalAddresses, StableAddresses
{
    public const PRIVATE_RANGES = ['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16'];

    public const SKIPPED_INTERFACES = '/^(lo|docker|br-|veth|virbr|cni|flannel|cali|coresmith-door)/';

    /**
     * @param  list<string>  $stable
     * @param  list<string>  $addresses
     * @param  list<string>  $private
     */
    public function __construct(
        public array $stable,
        public array $addresses,
        public ?DirectReach $direct,
        public array $private = [],
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
            self::private($sections['private'] ?? []),
        );
    }

    /**
     * @param  list<string>  $lines
     * @return list<string>
     */
    private static function private(array $lines): array
    {
        $kept = [];

        foreach ($lines as $line) {
            $parts = preg_split('/\s+/', trim($line)) ?: [];

            if (count($parts) !== 2 || preg_match(self::SKIPPED_INTERFACES, $parts[0]) === 1) {
                continue;
            }

            $address = explode('/', $parts[1])[0];

            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false && IpUtils::checkIp($address, self::PRIVATE_RANGES)) {
                $kept[] = $parts[1];
            }
        }

        return array_values(array_unique($kept));
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
