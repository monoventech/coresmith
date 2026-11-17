<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Database;

use App\Enums\DatabaseType;
use App\Enums\PrivilegeLevel;
use App\Jobs\CreateDatabase;
use App\Models\ManagedDatabase;
use App\Models\Server;
use RuntimeException;

final readonly class DatabaseCreator
{
    public function __construct(private RedisPorts $redisPorts) {}

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws RuntimeException
     */
    public function create(Server $server, array $attributes): ManagedDatabase
    {
        $attributes = [...$attributes, 'server_id' => $server->getKey(), 'team_id' => $server->team_id, 'status' => ManagedDatabase::CREATING];
        $type = $attributes['type'] instanceof DatabaseType ? $attributes['type'] : DatabaseType::from((string) $attributes['type']);

        $database = $type === DatabaseType::Redis
            ? $this->redisPorts->lock($server)->block(RedisPorts::LOCK_SECONDS, fn(): ManagedDatabase => ManagedDatabase::create([
                ...$attributes,
                'db_user' => null,
                'privilege_level' => PrivilegeLevel::Standard->value,
                'port' => $this->redisPorts->next($server),
            ]))
            : ManagedDatabase::create([...$attributes, 'port' => $type === DatabaseType::Postgresql ? 5432 : 3306]);

        CreateDatabase::dispatch($database);

        return $database;
    }

    public function freeName(string $base): string
    {
        $base = substr(trim((string) preg_replace('/[^a-z0-9_]+/', '_', strtolower($base)), '_'), 0, 48) ?: 'app';
        $base = ctype_digit($base[0]) ? 'app_' . $base : $base;
        $name = $base;

        for ($suffix = 2; ManagedDatabase::withTrashed()->where('name', $name)->exists(); $suffix++) {
            $name = $base . '_' . $suffix;
        }

        return $name;
    }
}
