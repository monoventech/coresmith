<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Plan;
use App\Enums\TeamRole;
use App\Models\Subscription;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Team>
 */
class TeamFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'owner_id' => User::factory(),
        ];
    }

    public function owned(): static
    {
        return $this->afterCreating(function (Team $team): void {
            $team->members()->attach($team->owner_id, ['role' => TeamRole::Owner->value]);
            User::query()->whereKey($team->owner_id)->update(['current_team_id' => $team->getKey()]);
        });
    }

    public function onTrial(int $days = 14): static
    {
        return $this->state(['trial_ends_at' => now()->addDays($days)]);
    }

    public function trialEnded(int $daysAgo = 1): static
    {
        return $this->state(['trial_ends_at' => now()->subDays($daysAgo)]);
    }

    public function subscribed(Plan $plan, string $status = 'active'): static
    {
        return $this->state(fn(): array => ['stripe_id' => 'cus_' . Str::lower(Str::random(14))])
            ->afterCreating(function (Team $team) use ($plan, $status): void {
                Subscription::factory()->for($team, 'owner')->plan($plan)->status($status)->create();
            });
    }
}
