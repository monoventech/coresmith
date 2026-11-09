<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Database;

use App\Enums\DatabaseType;

final class CoresmithDatabase
{
    private const DRIVERS = ['mysql' => DatabaseType::Mysql, 'mariadb' => DatabaseType::Mysql, 'pgsql' => DatabaseType::Postgresql];

    private const LOOPBACK = ['', '127.0.0.1', 'localhost', '::1'];

    /**
     * @param  array<string, mixed>  $connection
     */
    public function __construct(private readonly array $connection) {}

    public static function fromConfig(): self
    {
        return new self((array) config('database.connections.' . config('database.default')));
    }

    public function sharesEngineOnThisMachine(DatabaseType $type): bool
    {
        return (self::DRIVERS[(string) ($this->connection['driver'] ?? '')] ?? null) === $type
            && in_array(strtolower((string) ($this->connection['host'] ?? '')), self::LOOPBACK, true);
    }

    public function named(?string $name): bool
    {
        return $name !== null && strcasecmp($name, (string) ($this->connection['database'] ?? '')) === 0;
    }

    public function usedBy(?string $user): bool
    {
        return $user !== null && strcasecmp($user, (string) ($this->connection['username'] ?? '')) === 0;
    }
}
