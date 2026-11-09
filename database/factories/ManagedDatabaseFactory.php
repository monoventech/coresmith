<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DatabaseType;
use App\Models\ManagedDatabase;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ManagedDatabase>
 */
class ManagedDatabaseFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'server_id' => Server::factory(),
            'team_id' => Team::factory(),
            'type' => DatabaseType::Mysql,
            'name' => fake()->unique()->slug(1),
            'db_user' => fake()->unique()->userName(),
            'db_password' => Str::random(24),
            'port' => 3306,
        ];
    }

    public function postgres(): static
    {
        return $this->state(fn(array $attributes): array => [
            'type' => DatabaseType::Postgresql,
            'port' => 5432,
        ]);
    }

    public function redis(): static
    {
        return $this->state(fn(array $attributes): array => [
            'type' => DatabaseType::Redis,
            'port' => ManagedDatabase::SHARED_REDIS_PORT,
        ]);
    }
}
