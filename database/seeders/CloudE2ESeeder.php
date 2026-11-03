<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\DatabaseType;
use App\Enums\Plan;
use App\Enums\ServerStatus;
use App\Enums\TeamRole;
use App\Models\Application;
use App\Models\BackupDestination;
use App\Models\ManagedDatabase;
use App\Models\Server;
use App\Models\Subscription;
use App\Models\Team;
use App\Models\TeamDomain;
use App\Models\TeamInvitation;
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

    public static function invitationToken(string $code): string
    {
        return str_pad("e2eCloudInvite{$code}", TeamInvitation::TOKEN_LENGTH, '0');
    }

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
        $this->invitations($tenant->team, $tenant->owner);

        $this->trialTeam($tenant->owner);
        $this->lapsedTeam($tenant->owner, $tenant->member);
        $this->endingTeam($tenant->owner);
        $this->overdueTeam($tenant->owner);
        $this->soloTeam($tenant->owner);
        $this->fleetTeam($tenant->owner);
        $this->businessTeam($tenant->owner);
        $this->oldPriceTeam($tenant->owner);

        foreach (self::PROJECTS as $code) {
            $this->planChangeTeams($tenant->owner, $code);
            $this->vaultTeam($tenant->owner, $code);
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

    private function businessTeam(User $owner): void
    {
        $team = $this->ownedTeam($owner, 'Atelier Business', 'atelier');
        $team->forceFill(['stripe_id' => 'cus_e2e_atelier'])->save();

        Subscription::factory()->for($team, 'owner')->plan(Plan::Business)->create();

        $server = Server::factory()->for($team)->create(['name' => 'atelier-01', 'status' => ServerStatus::Active]);

        activity()->performedOn($team)->causedBy($owner)->withProperties(['email' => $owner->email, 'role' => 'owner'])->log('member_added');
        activity()->performedOn($server)->causedBy($owner)->log('server_provisioned');
        activity()->performedOn($server)->withProperties(['command' => 'systemctl reload caddy'])->log('ssh_command_executed');

        BackupDestination::factory()->for($team)->create([
            'region' => 'eu-central-1',
            'bucket' => 'atelier-backups',
            'prefix' => 'nightly',
            'access_key_id' => 'AKIAEXAMPLE0000000000',
            'secret_access_key' => 'an-e2e-secret-access-key',
            'last_run_at' => now()->subHours(2),
        ]);

        ManagedDatabase::factory()->for($team)->for($server)->postgres()->create([
            'name' => 'atelier_main',
            'last_offsite_at' => now()->subHours(2),
            'last_offsite_key' => 'nightly/atelier/atelier-01/atelier_main/atelier_main-' . now()->subHours(2)->format('YmdHis') . '.sql.gz',
        ]);
        ManagedDatabase::factory()->for($team)->for($server)->create([
            'type' => DatabaseType::Mysql,
            'name' => 'atelier_legacy',
            'last_offsite_error' => 'The bucket refused the upload: curl: (22) The requested URL returned error: 403. Check the bucket name, region and keys.',
        ]);
    }

    private function oldPriceTeam(User $owner): void
    {
        $team = $this->ownedTeam($owner, 'Old Price', 'old-price');
        $team->forceFill(['stripe_id' => 'cus_e2e_old_price'])->save();

        Subscription::factory()->for($team, 'owner')->plan(Plan::Pro)->create(['renews_at' => now()->addDays(13), 'unit_amount' => 1500, 'currency' => 'usd']);
    }

    private function vaultTeam(User $owner, string $code): void
    {
        $team = $this->ownedTeam($owner, "Vault {$code}", "vault-{$code}");
        $team->forceFill(['stripe_id' => "cus_e2e_vault_{$code}"])->save();

        Subscription::factory()->for($team, 'owner')->plan(Plan::Business)->create();

        $server = Server::factory()->for($team)->create(['name' => "vault-{$code}-01", 'status' => ServerStatus::Active]);
        ManagedDatabase::factory()->for($team)->for($server)->postgres()->create(['name' => 'vault_main']);
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

    private function invitations(Team $team, User $owner): void
    {
        foreach (self::PROJECTS as $code) {
            TeamInvitation::factory()->for($team)->withToken(self::invitationToken($code))->create(['email' => "joiner-{$code}@cloud.test", 'invited_by' => $owner->id]);
        }
    }

    private function invoices(Team $team): void
    {
        foreach ([1, 2, 3, 4] as $index) {
            $number = sprintf('MNV-%04d', $index);

            TeamInvoice::factory()->for($team)->create([
                'number' => $number,
                'period_start' => now()->startOfMonth()->subMonths(4 - $index),
                'pdf_url' => (config('coresmith.billing.stripe_api_base') ?: 'http://127.0.0.1:8126') . "/invoices/{$number}.pdf",
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
