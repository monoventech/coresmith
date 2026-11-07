<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ApplicationType;
use App\Models\Application;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Application>
 */
class ApplicationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $server = Server::factory();

        return [
            'server_id' => $server,
            'team_id' => Team::factory(),
            'name' => fake()->words(2, true),
            'slug' => fake()->unique()->slug(2),
            'type' => ApplicationType::Php,
            'framework' => 'laravel',
            'repository_url' => 'https://github.com/' . fake()->userName() . '/' . fake()->slug(1),
            'system_user' => 'coresmith-app-' . fake()->unique()->bothify('????'),
            'base_port' => fake()->unique()->numberBetween(800, 999) * 10,
            'php_version' => '8.5.5',
        ];
    }
}
