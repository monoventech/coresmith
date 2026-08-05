<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ApplicationType;
use App\Enums\ServerStatus;
use App\Enums\TeamRole;
use App\Models\Application;
use App\Models\ManagedDatabase;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

final class E2ESeeder extends Seeder
{
    public const OWNER_EMAIL = 'owner@coresmith.test';

    public const MEMBER_EMAIL = 'member@coresmith.test';

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

        $team = Team::factory()->create(['name' => 'Monoven', 'owner_id' => $owner->id]);
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

        Application::factory()->for($team)->for($server)->create([
            'name' => 'Storefront',
            'slug' => 'storefront',
            'system_user' => 'coresmith-app-storefront',
            'type' => ApplicationType::Php,
            'framework' => 'laravel',
            'domain' => 'storefront.example.com',
            'base_port' => 8000,
        ]);

        ManagedDatabase::factory()->for($team)->for($server)->create([
            'name' => 'storefront_production',
            'db_user' => 'storefront_user',
        ]);

        Server::factory()->for($team)->create([
            'name' => 'empty-server',
            'ip_address' => '203.0.113.11',
            'status' => ServerStatus::Pending,
        ]);
    }
}
