<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Plan;
use App\Enums\ServerStatus;
use App\Enums\TeamRole;
use App\Models\Application;
use App\Models\Server;
use App\Models\Subscription;
use App\Models\Team;
use App\Models\TeamDomain;
use App\Models\TeamInvoice;
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
        $this->invoices($tenant->team);

        $this->trialTeam($tenant->owner);
        $this->lapsedTeam($tenant->owner, $tenant->member);
        $this->endingTeam($tenant->owner);
        $this->overdueTeam($tenant->owner);
        $this->soloTeam($tenant->owner);
        $this->fleetTeam($tenant->owner);

        foreach (self::PROJECTS as $code) {
            $this->planChangeTeams($tenant->owner, $code);
        }

        $tenant->owner->forceFill(['current_team_id' => $tenant->team->getKey()])->save();
    }

    private function trialTeam(User $owner): void
    {
        $team = Team::factory()->create(['name' => 'Forge Trial', 'slug' => 'trial', 'owner_id' => $owner->getKey()]);
        $team->forceFill(['trial_ends_at' => now()->addDays(9)])->save();
        $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

        Server::factory()->for($team)->create(['name' => 'forge-trial-01', 'status' => ServerStatus::Active]);
    }

    private function lapsedTeam(User $owner, User $member): void
    {
        $team = $this->ownedTeam($owner, 'Lapsed Labs', 'expired');
        $team->forceFill(['trial_ends_at' => now()->subDays(3)])->save();
        $team->members()->attach($member, ['role' => TeamRole::Member->value]);

        $server = Server::factory()->for($team)->create(['name' => 'lapsed-01', 'status' => ServerStatus::Active]);
        Application::factory()->for($team)->for($server)->create(['name' => 'lapsed-app']);
    }

    private function endingTeam(User $owner): void
    {
        $this->ownedTeam($owner, 'Last Days', 'ending')->forceFill(['trial_ends_at' => now()->addDays(2)])->save();
    }

    private function overdueTeam(User $owner): void
    {
        $team = $this->ownedTeam($owner, 'Overdue Co', 'overdue');
        $team->forceFill(['stripe_id' => 'cus_e2e_overdue', 'pm_type' => 'visa', 'pm_last_four' => '0341', 'pm_expires' => '02/27'])->save();

        Subscription::factory()->for($team, 'owner')->plan(Plan::Pro)->status('past_due')->create();
        TeamInvoice::factory()->for($team)->failed(now()->addDays(3))->create();
    }

    private function soloTeam(User $owner): void
    {
        $team = $this->ownedTeam($owner, 'Solo Shop', 'solo');
        $team->forceFill(['stripe_id' => 'cus_e2e_solo'])->save();

        Subscription::factory()->for($team, 'owner')->plan(Plan::Starter)->create();

        foreach (['solo-01', 'solo-02', 'solo-03'] as $name) {
            Server::factory()->for($team)->create(['name' => $name, 'status' => ServerStatus::Active]);
        }
    }

    private function fleetTeam(User $owner): void
    {
        $team = $this->ownedTeam($owner, 'Big Fleet', 'fleet');
        $team->forceFill(['stripe_id' => 'cus_e2e_fleet'])->save();

        Subscription::factory()->for($team, 'owner')->plan(Plan::Pro)->create();
        TeamDomain::factory()->for($team)->verified()->create(['hostname' => 'deploy.bigfleet.test']);

        foreach (['Ines Okafor' => 'fleet-ops@cloud.test', 'Tomas Reyes' => 'fleet-dev@cloud.test'] as $name => $email) {
            $team->members()->attach(User::factory()->create(['name' => $name, 'email' => $email]), ['role' => TeamRole::Member->value]);
        }

        foreach (['fleet-01', 'fleet-02', 'fleet-03', 'fleet-04', 'fleet-05'] as $name) {
            Server::factory()->for($team)->create(['name' => $name, 'status' => ServerStatus::Active]);
        }
    }

    private function planChangeTeams(User $owner, string $code): void
    {
        $pay = $this->ownedTeam($owner, "Pay {$code}", "pay-{$code}");
        $pay->forceFill(['stripe_id' => "cus_e2e_pay_{$code}", 'trial_ends_at' => now()->addDays(9)])->save();

        foreach (['swap' => null, 'end' => null, 'keep' => now()->addDays(10)] as $prefix => $endsAt) {
            $team = $this->ownedTeam($owner, ucfirst($prefix) . " {$code}", "{$prefix}-{$code}");
            $team->forceFill(['stripe_id' => "cus_e2e_{$prefix}_{$code}"])->save();

            $subscription = Subscription::factory()->for($team, 'owner')->plan(Plan::Pro)->create([
                'stripe_id' => "sub_e2e_{$prefix}_{$code}",
                'ends_at' => $endsAt,
                'renews_at' => now()->addDays(10),
            ]);
            $subscription->items()->update(['stripe_id' => "si_e2e_{$prefix}_{$code}"]);
        }
    }

    private function invoices(Team $team): void
    {
        foreach ([1, 2, 3, 4] as $index) {
            $number = sprintf('MNV-%04d', $index);

            TeamInvoice::factory()->for($team)->create([
                'number' => $number,
                'period_start' => now()->startOfMonth()->subMonths(4 - $index),
                'pdf_url' => "http://127.0.0.1:8126/invoices/{$number}.pdf",
            ]);
        }
    }

    private function ownedTeam(User $owner, string $name, string $slug): Team
    {
        $team = Team::factory()->create(['name' => $name, 'slug' => $slug, 'owner_id' => $owner->getKey()]);
        $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

        return $team;
    }
}
