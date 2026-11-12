<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Project;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'name' => ucfirst(fake()->unique()->word()),
        ];
    }

    /**
     * @param  list<string>  $names
     */
    public function withEnvironments(array $names = ['Production']): static
    {
        return $this->afterCreating(function (Project $project) use ($names): void {
            foreach ($names as $position => $name) {
                $project->environments()->create(['name' => $name, 'position' => $position]);
            }
        });
    }
}
