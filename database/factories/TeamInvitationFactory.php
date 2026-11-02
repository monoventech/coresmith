<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\TeamInvitation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<TeamInvitation>
 */
class TeamInvitationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'email' => Str::lower(fake()->unique()->safeEmail()),
            'role' => TeamRole::Member,
            'token_hash' => TeamInvitation::hash(Str::random(TeamInvitation::TOKEN_LENGTH)),
            'expires_at' => now()->addDays(TeamInvitation::VALID_DAYS),
        ];
    }

    public function withToken(string $token): static
    {
        return $this->state(['token_hash' => TeamInvitation::hash($token)]);
    }

    public function expired(): static
    {
        return $this->state(fn(): array => ['expires_at' => now()->subMinute()]);
    }

    public function accepted(): static
    {
        return $this->state(fn(): array => ['accepted_at' => now()]);
    }
}
