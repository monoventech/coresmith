<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Git\GitHub;

final readonly class Reachability
{
    public const LOOPBACK = 'loopback';

    public const PRIVATE = 'private';

    public const LOCAL = 'local';

    public const IP = 'ip';

    public const HTTP = 'http';

    public function __construct(
        public bool $reachable,
        public string $host,
        public ?string $reason,
        public ?string $address,
    ) {}

    /**
     * @param  array<mixed>  $data
     */
    public static function fromArray(array $data): ?self
    {
        if (!is_bool($data['reachable'] ?? null) || !is_string($data['host'] ?? null)) {
            return null;
        }

        $reason = $data['reason'] ?? null;
        $address = $data['address'] ?? null;

        if (($reason !== null && !is_string($reason)) || ($address !== null && !is_string($address))) {
            return null;
        }

        return new self($data['reachable'], $data['host'], $reason, $address);
    }

    /**
     * @return array{reachable: bool, host: string, reason: ?string, address: ?string}
     */
    public function toArray(): array
    {
        return ['reachable' => $this->reachable, 'host' => $this->host, 'reason' => $this->reason, 'address' => $this->address];
    }

    public function sentence(): ?string
    {
        $host = $this->host === '' ? 'Coresmith' : $this->host;

        return match ($this->reason) {
            self::LOOPBACK => "GitHub can't reach {$host}, because it only answers on this machine.",
            self::PRIVATE => $this->address === null || $this->address === $this->host
                ? "GitHub can't reach {$host}, because it isn't a public address."
                : "GitHub can't reach {$host}, because {$this->address} isn't a public address.",
            self::LOCAL => "GitHub can't reach {$host}, because it only resolves on your own network.",
            self::IP => "GitHub cannot check a certificate for {$host}. Give Coresmith a domain name to get pushes as they happen.",
            self::HTTP => 'Coresmith is served over http, and GitHub only delivers to https.',
            default => null,
        };
    }
}
