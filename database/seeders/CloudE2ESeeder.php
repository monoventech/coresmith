<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Plan;
use App\Enums\ServerStatus;
use App\Enums\TeamRole;
use App\Models\Server;
use App\Models\Subscription;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\Support\MonovenTenant;
use Illuminate\Database\Seeder;

final class CloudE2ESeeder extends Seeder
{
    public const OWNER_EMAIL = 'owner@cloud.test';

    public const ADMIN_EMAIL = 'admin@cloud.test';

    public const MEMBER_EMAIL = 'member@cloud.test';

    public const PASSWORD = 'password-for-e2e';

    public const PROJECTS = E2ESeeder::PROJECTS;

    public function run(): void
    {
        $tenant = (new MonovenTenant(E2ESeeder::METRICS_AHEAD_MINUTES, E2ESeeder::METRICS_AHEAD_MINUTES))->build([
            'owner' => self::OWNER_EMAIL,
            'admin' => self::ADMIN_EMAIL,
            'member' => self::MEMBER_EMAIL,
            'password' => self::PASSWORD,
        ]);

        $tenant->team->forceFill(['stripe_id' => 'cus_e2e_monoven', 'pm_type' => 'visa', 'pm_last_four' => '4242', 'pm_expires' => '08/28'])->save();
        Subscription::factory()->for($tenant->team, 'owner')->plan(Plan::Pro)->create(['renews_at' => now()->addDays(13)]);

        $this->trialTeam($tenant->owner);

        $tenant->owner->forceFill(['current_team_id' => $tenant->team->getKey()])->save();
    }

    private function trialTeam(User $owner): void
    {
        $team = Team::factory()->create(['name' => 'Forge Trial', 'slug' => 'trial', 'owner_id' => $owner->getKey()]);
        $team->forceFill(['trial_ends_at' => now()->addDays(9)])->save();
        $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

        Server::factory()->for($team)->create(['name' => 'forge-trial-01', 'status' => ServerStatus::Active]);
    }
}
