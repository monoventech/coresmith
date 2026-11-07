<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CloudProviderType;
use App\Models\CloudProvider;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CloudProvider>
 */
class CloudProviderFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'type' => 'hetzner',
            'name' => fake()->company() . ' Hetzner',
            'api_token' => Str::random(48),
            'verified_at' => now(),
        ];
    }

    public function digitalocean(): static
    {
        return $this->state(['type' => CloudProviderType::DigitalOcean->value]);
    }

    public function vultr(): static
    {
        return $this->state(['type' => CloudProviderType::Vultr->value]);
    }

    public function linode(): static
    {
        return $this->state(['type' => CloudProviderType::Linode->value]);
    }

    public function refused(): static
    {
        return $this->state(['token_failed_at' => now()]);
    }
}
