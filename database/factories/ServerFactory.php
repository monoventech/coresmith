<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ServerStatus;
use App\Models\Server;
use App\Models\Team;
use App\Services\SSH\KeyPair;
use App\Services\SSH\KeyPairGenerator;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Server>
 */
class ServerFactory extends Factory
{
    private static ?KeyPair $keyPair = null;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $keyPair = static::$keyPair ??= (new KeyPairGenerator)->generate();

        return [
            'team_id' => Team::factory(),
            'name' => fake()->domainWord() . '-' . fake()->numberBetween(1, 99),
            'ip_address' => fake()->ipv4(),
            'ssh_port' => 22,
            'ssh_user' => 'root',
            'ssh_private_key' => $keyPair->privateKey,
            'ssh_public_key' => $keyPair->publicKey,
        ];
    }

    public function active(): static
    {
        return $this->state(fn(array $attributes): array => [
            'status' => ServerStatus::Active,
            'os_name' => 'ubuntu',
            'os_version' => '24.04',
            'last_connected_at' => now(),
        ]);
    }
}
