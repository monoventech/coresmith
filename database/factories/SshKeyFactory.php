<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace Database\Factories;

use App\Models\SshKey;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SshKey>
 */
class SshKeyFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->words(2, true),
            'public_key' => 'ssh-ed25519 '.fake()->sha256(),
            'fingerprint' => 'SHA256:'.fake()->sha256(),
        ];
    }
}
