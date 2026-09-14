<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Team;
use App\Models\TeamSsoConnection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<TeamSsoConnection>
 */
class TeamSsoConnectionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'issuer' => 'https://idp.' . fake()->unique()->domainWord() . '.example.test',
            'client_id' => 'coresmith-' . Str::lower(Str::random(8)),
            'client_secret' => Str::random(40),
            'email_domain' => fake()->unique()->domainWord() . '.example.test',
            'domain_token' => Str::lower(Str::random(32)),
            'domain_verified_at' => null,
            'enabled' => false,
            'jit_provisioning' => false,
        ];
    }

    public function verified(): static
    {
        return $this->state(fn(): array => ['domain_verified_at' => now()]);
    }

    public function enabled(): static
    {
        return $this->state(fn(): array => ['domain_verified_at' => now(), 'enabled' => true]);
    }

    public function provisioning(): static
    {
        return $this->state(fn(): array => ['jit_provisioning' => true]);
    }
}
