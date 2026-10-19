<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace Database\Factories;

use App\Models\GitHubApp;
use App\Models\Team;
use App\Services\Teams\TeamUrls;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<GitHubApp>
 */
class GitHubAppFactory extends Factory
{
    private static ?string $pem = null;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $word = fake()->word();

        return [
            'id' => strtolower((string) Str::ulid()),
            'team_id' => Team::factory(),
            'app_id' => (string) fake()->unique()->numberBetween(1000000, 9999999),
            'slug' => "coresmith-{$word}",
            'name' => 'Coresmith ' . Str::headline($word),
            'client_id' => 'Iv1.' . bin2hex(random_bytes(8)),
            'client_secret' => bin2hex(random_bytes(20)),
            'webhook_secret' => bin2hex(random_bytes(20)),
            'private_key' => self::pem(),
            'owner_login' => fake()->slug(2),
            'owner_type' => 'Organization',
            'html_url' => "https://github.com/apps/coresmith-{$word}",
            'permissions' => ['contents' => 'read', 'metadata' => 'read', 'statuses' => 'write'],
            'events' => ['push'],
            'hook_url' => fn(array $attributes): string => app(TeamUrls::class)->canonical(route('webhooks.github.app', $attributes['id'], false)),
        ];
    }

    public function withoutWebhook(): static
    {
        return $this->state(fn(): array => ['hook_url' => null, 'webhook_secret' => null]);
    }

    private static function pem(): string
    {
        if (self::$pem === null) {
            openssl_pkey_export(openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]), $pem);

            self::$pem = $pem;
        }

        return self::$pem;
    }
}
