<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Team;
use App\Models\TeamDomain;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<TeamDomain>
 */
class TeamDomainFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'hostname' => 'panel.' . fake()->unique()->domainWord() . '.example.com',
            'verification_token' => Str::lower(Str::random(32)),
        ];
    }

    public function verified(): static
    {
        return $this->state(fn(): array => ['verified_at' => now(), 'checked_at' => now()]);
    }
}
