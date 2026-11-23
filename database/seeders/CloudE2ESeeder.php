<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ApplicationStatus;
use App\Enums\ApplicationType;
use App\Enums\DatabaseType;
use App\Enums\Plan;
use App\Enums\ServerStatus;
use App\Enums\TeamRole;
use App\Models\Application;
use App\Models\BackupDestination;
use App\Models\CloudProvider;
use App\Models\ManagedDatabase;
use App\Models\Server;
use App\Models\Subscription;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\TeamInvoice;
use App\Models\User;
use App\Services\Billing\TrialHistory;
use App\Services\Cloud\Tasks\CloudTask;
use App\Services\Moves\MoveCodes;
use App\Services\Moves\MoveFile;
use App\Services\SSH\KeyPairGenerator;
use App\Services\Teams\TeamAccess;
use Database\Seeders\Support\MonovenTenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

final class CloudE2ESeeder extends Seeder
{
    public const OWNER_EMAIL = 'owner@cloud.test';

    public const ADMIN_EMAIL = 'admin@cloud.test';

    public const MEMBER_EMAIL = 'member@cloud.test';

    public const DEVELOPER_EMAIL = 'developer@cloud.test';

    public const PASSWORD = 'password-for-e2e';

    public const PROJECTS = E2ESeeder::PROJECTS;

    public static function leaverEmail(string $code): string
    {
        return "leaver-{$code}@cloud.test";
    }

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
        $this->developer($tenant);

        $this->trialTeam($tenant->owner);
        $this->lapsedTeam($tenant->owner, $tenant->member);
        $this->endingTeam($tenant->owner);
        $this->overdueTeam($tenant->owner);
        $this->soloTeam($tenant->owner);
        $this->fleetTeam($tenant->owner);
        $this->businessTeam($tenant->owner);
        $this->oldPriceTeam($tenant->owner);
        $this->noCardTeam($tenant->owner);
        $this->cardDueTeam($tenant->owner);
        $this->freeTeams($tenant->owner);
        $this->endedBusinessTeam($tenant->owner);

        foreach (self::PROJECTS as $code) {
            $this->planChangeTeams($tenant->owner, $code);
            $this->vaultTeam($tenant->owner, $code);
            $this->leftoverTeam($tenant->owner, $code);
            $this->partingTeam($code);
            $this->moveTeams($tenant->owner, $code);
            $this->arrivingTeams($tenant->owner, $code);
        }

        $this->movingTeam($tenant->owner, 'Moved Away', 'moved-away');
        $this->arriving($tenant->owner, 'Arriving Big', 'arriving-big', 'big', 2);

        $tenant->owner->forceFill(['current_team_id' => $tenant->team->getKey()])->save();
    }

    private function developer(MonovenTenant $tenant): void
    {
        $developer = User::factory()->create(['name' => 'Lena Brandt', 'email' => self::DEVELOPER_EMAIL, 'password' => Hash::make(self::PASSWORD)]);
        $tenant->team->members()->attach($developer, ['role' => TeamRole::Developer->value, 'sees_everything' => false]);
        app(TeamAccess::class)->grant($tenant->team, $developer, $tenant->servers['forge-02']);

        Application::factory()->for($tenant->team)->for($tenant->servers['forge-02'])->create([
            'name' => 'side-notes',
            'slug' => 'side-notes',
            'system_user' => 'coresmith-app-side-notes',
            'type' => ApplicationType::Php,
            'framework' => 'laravel',
            ...MonovenTenant::stack(['php' => ['8.5.10', 'composer.json', '^8.4']], ['name' => 'laravel', 'major' => 13]),
            'domain' => 'side-notes.lena.dev',
            'repository_url' => 'https://github.com/monoventech/side-notes',
            'repository_branch' => 'main',
            'base_port' => 9620,
            'status' => ApplicationStatus::Active,
            'created_by' => $developer->id,
        ]);

        Server::factory()->for($tenant->team)->create([
            'name' => 'lena-01',
            'region' => 'ams3',
            'ip_address' => '198.51.100.44',
            'status' => ServerStatus::Active,
            'os_name' => 'ubuntu',
            'os_version' => '24.04',
            'meta' => ['cpu_cores' => 2, 'memory_mb' => 4096, 'disk_gb' => 80],
            'provisioning_phase' => 8,
            'host_key_fingerprint' => 'SHA256:' . substr(base64_encode(hash('sha256', 'lena-01', true)), 0, 43),
            'last_connected_at' => now(),
            'created_by' => $developer->id,
        ]);
    }

    private function moveTeams(User $owner, string $code): void
    {
        $team = $this->ownedTeam($owner, "Move {$code}", "move-{$code}");
        $team->forceFill(['trial_ends_at' => now()->addDays(9)])->save();
        $server = Server::factory()->for($team)->active()->create(['name' => "move-{$code}-01", 'host_key_fingerprint' => 'SHA256:' . substr(base64_encode(hash('sha256', "move-{$code}", true)), 0, 43)]);
        Application::factory()->for($team)->for($server)->create(['name' => "move-{$code}-site", 'slug' => "move-{$code}-site", 'system_user' => "coresmith-app-mv{$code}"]);

        $this->movingTeam($owner, "Moving {$code}", "moving-{$code}");
    }

    private function movingTeam(User $owner, string $name, string $slug): void
    {
        $team = $this->ownedTeam($owner, $name, $slug);
        $team->forceFill(['trial_ends_at' => now()->addDays(9), 'moving_at' => now()->subHour(), 'moving_to' => 'https://panel.example.com', 'move_file' => '{"format":1}'])->save();
        Server::factory()->for($team)->create(['name' => "{$slug}-01", 'status' => ServerStatus::Moving, 'os_name' => 'ubuntu', 'os_version' => '24.04', 'host_key_fingerprint' => 'SHA256:' . substr(base64_encode(hash('sha256', $slug, true)), 0, 43)]);
    }

    private function arrivingTeams(User $owner, string $code): void
    {
        $this->arriving($owner, "Arriving {$code}", "arriving-{$code}", $code, 1);

        $fresh = $this->ownedTeam($owner, "Fresh {$code}", "fresh-{$code}");
        $fresh->forceFill(['trial_ends_at' => now()->addDays(9)])->save();
    }

    private function arriving(User $owner, string $name, string $slug, string $code, int $servers): void
    {
        $team = $this->ownedTeam($owner, $name, $slug);
        $team->forceFill(['trial_ends_at' => now()->addDays(9)])->save();
        $codes = app(MoveCodes::class);
        $move = $codes->create($owner, $team);
        $move->forceFill(['file' => app(MoveFile::class)->seal($this->selfHostedTeam($code, $servers), $codes->read($codes->text($move)))])->save();
    }

    /**
     * @return array<string, mixed>
     */
    private function selfHostedTeam(string $code, int $servers): array
    {
        $records = [];
        $offset = array_search($code, array_values(self::PROJECTS), true);
        $base = 100 + 10 * (is_int($offset) ? $offset : 5);

        foreach (range(1, $servers) as $n) {
            $key = (new KeyPairGenerator)->generate("arrivals-{$code}-{$n}");
            $records[] = [
                'ref' => "s{$n}", 'cloud_provider' => null, 'tags' => [], 'name' => "arrivals-{$code}-0{$n}", 'ip_address' => '203.0.113.' . ($base + $n), 'ssh_port' => 22, 'ssh_user' => 'root',
                'ssh_private_key' => $key->privateKey, 'ssh_public_key' => $key->publicKey, 'host_key_fingerprint' => 'SHA256:' . substr(base64_encode(hash('sha256', "arrivals-{$code}-{$n}", true)), 0, 43),
                'os_name' => 'ubuntu', 'os_version' => '24.04', 'provisioning_phase' => 8, 'installed_runtimes' => [], 'meta' => ['cpu_cores' => 2, 'memory_mb' => 4096, 'disk_gb' => 80],
                'audit_logging_enabled' => false, 'region' => 'home',
            ];
        }

        return [
            'id' => (string) Str::uuid(),
            'made_at' => now()->toIso8601String(),
            'from' => 'https://coresmith.example.com',
            'edition' => 'self-hosted',
            'team' => ['name' => "Arrivals {$code}", 'slug' => "arrivals-{$code}"],
            'members' => [],
            'cloud_providers' => [],
            'servers' => $records,
            'source_providers' => [],
            'projects' => [],
            'applications' => [[
                'ref' => 'a1', 'server' => 's1', 'source_provider' => null, 'name' => 'shop', 'slug' => "arrivals-{$code}-shop", 'type' => ApplicationType::Php->value,
                'framework' => 'laravel', 'repository_url' => 'https://github.com/arrivals/shop', 'system_user' => "coresmith-app-ar{$code}", 'base_port' => 8100,
                'php_version' => '8.5.5', 'status' => ApplicationStatus::Active->value,
            ]],
            'webhook_endpoints' => [],
            'databases' => [[
                'server' => 's1', 'type' => DatabaseType::Mysql->value, 'name' => 'shop', 'db_user' => 'shop', 'db_password' => Str::random(24), 'host' => '127.0.0.1', 'port' => 3306,
                'status' => 'active', 'privilege_level' => 'full', 'extensions' => [], 'is_external' => false,
            ]],
            'backup_destination' => null,
            'package_credentials' => [],
        ];
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

        foreach (self::PROJECTS as $code) {
            Server::factory()->for($team)->create([
                'name' => "lapsed-rekeyed-{$code}",
                'status' => ServerStatus::Active,
                'host_key_fingerprint' => E2ESeeder::ORIGINAL_HOST_KEY,
                'pending_host_key_fingerprint' => E2ESeeder::PRESENTED_HOST_KEY,
                'host_key_changed_at' => now(),
            ]);
        }
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
        ManagedDatabase::factory()->redis()->for($team)->for($server)->create(['name' => 'atelier_cache']);
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

    private function noCardTeam(User $owner): void
    {
        $team = $this->ownedTeam($owner, 'No Card', 'no-card');
        $team->forceFill(['stripe_id' => 'cus_e2e_no_card'])->save();

        Subscription::factory()->for($team, 'owner')->plan(Plan::Pro)->status('past_due')->create();
    }

    private function cardDueTeam(User $owner): void
    {
        $team = $this->ownedTeam($owner, 'Card Due', 'card-due');
        $team->forceFill(['stripe_id' => 'cus_e2e_card_due', 'pending_checkout_id' => 'cs_e2e_card_due'])->save();

        Subscription::factory()->for($team, 'owner')->plan(Plan::Pro)->create();
    }

    private function freeTeams(User $owner): void
    {
        $this->ownedTeam($owner, 'Free Forever', 'free')->forceFill(['free_plan' => Plan::Business])->save();
        $this->ownedTeam($owner, 'Free For Now', 'free-soon')->forceFill(['free_plan' => Plan::Starter, 'free_until' => now()->addDays(2)->endOfDay()])->save();
    }

    private function endedBusinessTeam(User $owner): void
    {
        $team = $this->ownedTeam($owner, 'Ended Business', 'ended-business');
        $team->forceFill(['stripe_id' => 'cus_e2e_ended_business'])->save();

        Subscription::factory()->for($team, 'owner')->plan(Plan::Business)->ended(now()->subDays(2))->create();
        BackupDestination::factory()->for($team)->create(['region' => 'eu-central-1', 'bucket' => 'ended-backups', 'prefix' => null]);
    }

    private function leftoverTeam(User $owner, string $code): void
    {
        $team = $this->ownedTeam($owner, "Leftover {$code}", "leftover-{$code}");
        $team->forceFill(['stripe_id' => "cus_e2e_leftover_{$code}"])->save();

        Subscription::factory()->for($team, 'owner')->plan(Plan::Pro)->create();
        BackupDestination::factory()->for($team)->create(['region' => 'eu-central-1', 'bucket' => 'leftover-backups', 'prefix' => null]);
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

    private function partingTeam(string $code): void
    {
        $leaver = User::factory()->create(['name' => 'Morgan Leaver', 'email' => self::leaverEmail($code), 'password' => Hash::make(self::PASSWORD)]);
        $team = Team::factory()->create(['name' => "Parting {$code}", 'slug' => "parting-{$code}", 'owner_id' => $leaver->getKey()]);
        $team->forceFill(['trial_ends_at' => now()->addDays(9)])->save();
        $team->members()->attach($leaver, ['role' => TeamRole::Owner->value]);
        $leaver->forceFill(['current_team_id' => $team->getKey()])->save();
        app(TrialHistory::class)->claim(self::leaverEmail($code), $team);

        $account = CloudProvider::factory()->for($team)->create(['name' => 'Parting Hetzner', 'api_token' => 'parting-e2e-never-sent']);
        Server::factory()->for($team)->create(['name' => "parting-{$code}-01", 'status' => ServerStatus::Active]);
        Server::factory()->for($team)->create(['name' => "parting-{$code}-02", 'status' => ServerStatus::Active, 'cloud_provider_id' => $account->getKey(), 'provider_server_id' => "parting-{$code}"]);

        $lost = Server::factory()->fromProvider($account)->creating()->create(['name' => "parting-{$code}-03", 'provider_server_id' => null, 'host_key_fingerprint' => null]);
        CloudTask::start($lost, CloudTask::CREATE, 'create', ['uncertain' => 3]);
        CloudTask::fail($lost, "Coresmith couldn't get an answer from Hetzner Cloud after three tries. Check the Hetzner Cloud console for a server called parting-{$code}-03: if there is one, delete it there, then remove this server and provision it again.");
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
