<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ApplicationType;
use App\Enums\DeploymentStatus;
use App\Enums\PowerState;
use App\Enums\ServerStatus;
use App\Enums\SourceProviderType;
use App\Enums\TeamRole;
use App\Models\Application;
use App\Models\Deployment;
use App\Models\ManagedDatabase;
use App\Models\Server;
use App\Models\ServerMetric;
use App\Models\SourceProvider;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

final class E2ESeeder extends Seeder
{
    public const OWNER_EMAIL = 'owner@coresmith.test';

    public const MEMBER_EMAIL = 'member@coresmith.test';

    public const SIGNOUT_EMAIL = 'signout@coresmith.test';

    public const PASSWORD = 'password-for-e2e';

    public function run(): void
    {
        $owner = User::factory()->create([
            'name' => 'Ada Owner',
            'email' => self::OWNER_EMAIL,
            'password' => Hash::make(self::PASSWORD),
            'email_verified_at' => now(),
        ]);

        $member = User::factory()->create([
            'name' => 'Bo Member',
            'email' => self::MEMBER_EMAIL,
            'password' => Hash::make(self::PASSWORD),
            'email_verified_at' => now(),
        ]);

        $signOut = User::factory()->create([
            'name' => 'Cy SignOut',
            'email' => self::SIGNOUT_EMAIL,
            'password' => Hash::make(self::PASSWORD),
            'email_verified_at' => now(),
        ]);

        $team = Team::factory()->create(['name' => 'Monoven', 'owner_id' => $owner->id]);
        $team->members()->attach($signOut, ['role' => TeamRole::Member->value]);
        $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
        $team->members()->attach($member, ['role' => TeamRole::Member->value]);

        $server = Server::factory()->for($team)->create([
            'name' => 'web-1',
            'ip_address' => '203.0.113.10',
            'status' => ServerStatus::Active,
            'os_name' => 'ubuntu',
            'os_version' => '24.04',
            'meta' => ['cpu_cores' => 4, 'memory_mb' => 8192, 'disk_gb' => 80],
            'last_connected_at' => now(),
        ]);

        foreach (range(29, 0) as $minutesAgo) {
            ServerMetric::create([
                'server_id' => $server->id,
                'cpu_percent' => 18 + ($minutesAgo % 7) * 2.5,
                'memory_percent' => 41 + ($minutesAgo % 5) * 1.5,
                'disk_percent' => 63.0,
                'load_1m' => 0.4 + ($minutesAgo % 4) * 0.15,
                'load_5m' => 0.5,
                'load_15m' => 0.6,
                'network_rx_bytes' => 1048576 * (30 - $minutesAgo),
                'network_tx_bytes' => 524288 * (30 - $minutesAgo),
                'recorded_at' => now()->subMinutes($minutesAgo),
            ]);
        }

        $storefront = Application::factory()->for($team)->for($server)->create([
            'name' => 'Storefront',
            'slug' => 'storefront',
            'system_user' => 'coresmith-app-storefront',
            'type' => ApplicationType::Php,
            'framework' => 'laravel',
            'domain' => 'storefront.example.com',
            'base_port' => 8000,
        ]);

        Application::factory()->for($team)->for($server)->create([
            'name' => 'Internal API',
            'slug' => 'internal-api',
            'system_user' => 'coresmith-app-internal-api',
            'type' => ApplicationType::Php,
            'framework' => 'laravel',
            'domain' => 'api.example.com',
            'repository_url' => 'git@github.com:monoven/internal-api.git',
            'base_port' => 8010,
        ]);

        ManagedDatabase::factory()->for($team)->for($server)->create([
            'name' => 'storefront_production',
            'db_user' => 'storefront_user',
        ]);

        Application::factory()->for($team)->for($server)->create([
            'name' => 'Retired Blog',
            'slug' => 'retired-blog',
            'system_user' => 'coresmith-app-retired-blog',
            'type' => ApplicationType::Php,
            'framework' => 'laravel',
            'domain' => 'blog.example.com',
            'power_state' => PowerState::Stopped,
            'base_port' => 8020,
        ]);

        Deployment::factory()->for($storefront)->create([
            'status' => DeploymentStatus::Succeeded,
            'commit_hash' => 'a1b2c3d4e5f60718293a4b5c6d7e8f9012345678',
            'commit_message' => 'Add the checkout summary',
            'log' => "Cloning into bare repository\ncomposer install --no-dev\nnpm run build\nDeployment finished",
            'started_at' => now()->subMinutes(4),
            'finished_at' => now()->subMinutes(3),
        ]);

        Deployment::factory()->for($storefront)->create([
            'status' => DeploymentStatus::Running,
            'commit_hash' => 'ff00aa11bb22cc33dd44ee55ff66007788990011',
            'commit_message' => 'Speed up the cart query',
            'log' => "Cloning into bare repository\ncomposer install --no-dev",
            'started_at' => now()->subSeconds(20),
        ]);

        SourceProvider::factory()->for($team)->create([
            'type' => SourceProviderType::GitHubApp->value,
            'name' => 'oseughu',
            'access_token' => 'ghs_e2e_never_rendered',
            'token_expires_at' => now()->addMinutes(102),
        ]);

        Server::factory()->for($team)->create([
            'name' => 'empty-server',
            'ip_address' => '203.0.113.11',
            'status' => ServerStatus::Pending,
        ]);
    }
}
