<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SourceProviderType;
use App\Models\GitHubApp;
use App\Models\SourceProvider;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SourceProvider>
 */
class SourceProviderFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'type' => 'github_app',
            'name' => fake()->company() . ' GitHub',
            'access_token' => 'ghs_' . Str::random(36),
            'installation_id' => (string) fake()->numberBetween(1000, 99999),
            'scopes' => ['repo'],
            'token_expires_at' => now()->addHour(),
        ];
    }

    public function githubApp(?GitHubApp $app = null): static
    {
        return $this->state([
            'type' => SourceProviderType::GitHubApp->value,
            'github_app_id' => $app ?? fn(array $attributes): string => (string) GitHubApp::factory()->create(['team_id' => $attributes['team_id']])->getKey(),
        ]);
    }
}
