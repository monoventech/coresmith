<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Server;
use App\Models\ServerMetric;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServerMetric>
 */
class ServerMetricFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'server_id' => Server::factory(),
            'cpu_percent' => fake()->randomFloat(2, 0, 100),
            'memory_percent' => fake()->randomFloat(2, 0, 100),
            'disk_percent' => fake()->randomFloat(2, 0, 100),
            'load_1m' => fake()->randomFloat(2, 0, 8),
            'load_5m' => fake()->randomFloat(2, 0, 8),
            'load_15m' => fake()->randomFloat(2, 0, 8),
            'network_rx_bytes' => fake()->numberBetween(0, 10_000_000),
            'network_tx_bytes' => fake()->numberBetween(0, 10_000_000),
            'recorded_at' => now(),
        ];
    }
}
