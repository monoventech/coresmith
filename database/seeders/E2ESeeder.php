<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ApplicationStatus;
use App\Enums\ApplicationType;
use App\Enums\DeploymentStatus;
use App\Enums\PowerState;
use App\Enums\ServerStatus;
use App\Enums\TeamRole;
use App\Models\Application;
use App\Models\Deployment;
use App\Models\ManagedDatabase;
use App\Models\Server;
use App\Models\Team;
use App\Models\TeamDomain;
use App\Models\TeamSsoConnection;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\Support\DemoLog;
use Database\Seeders\Support\MonovenTenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

final class E2ESeeder extends Seeder
{
    public const OWNER_EMAIL = 'owner@coresmith.test';

    public const ADMIN_EMAIL = 'jonas@coresmith.test';

    public const MEMBER_EMAIL = 'member@coresmith.test';

    public const SIGNOUT_EMAIL = 'signout@coresmith.test';

    public const ROLE_EMAIL = 'rowan@coresmith.test';

    public const PASSWORD = 'password-for-e2e';

    public const PROJECTS = ['chromium' => 'chr', 'firefox' => 'ffx', 'webkit' => 'wbk', 'mobile-chrome' => 'mch', 'mobile-safari' => 'msf'];

    public const METRICS_AHEAD_MINUTES = 6 * 60;

    public const ORIGINAL_HOST_KEY = 'SHA256:AAAAoriginalkeyfingerprintAAAAAAAAAAAAAAAAAA';

    public const PRESENTED_HOST_KEY = 'SHA256:BBBBimpostorkeyfingerprintBBBBBBBBBBBBBBBBBB';

    private Team $team;

    private Server $bench;

    public function run(): void
    {
        $tenant = (new MonovenTenant(self::METRICS_AHEAD_MINUTES))->build([
            'owner' => self::OWNER_EMAIL,
            'admin' => self::ADMIN_EMAIL,
            'member' => self::MEMBER_EMAIL,
            'password' => self::PASSWORD,
        ]);

        $this->team = $tenant->team;
        $password = Hash::make(self::PASSWORD);

        $this->team->members()->attach(User::factory()->create(['name' => 'Sam Okafor', 'email' => self::SIGNOUT_EMAIL, 'password' => $password]), ['role' => TeamRole::Member->value]);
        $this->team->members()->attach(User::factory()->create(['name' => 'Rowan Hale', 'email' => self::ROLE_EMAIL, 'password' => $password]), ['role' => TeamRole::Member->value]);

        $this->bench = $this->server('bench-01', '198.51.100.40', ServerStatus::Active);

        $this->application('retired-blog', 8000, ['power_state' => PowerState::Stopped]);
        $this->rolledBack($this->application('rolled-back', 8010));

        foreach (array_values(self::PROJECTS) as $index => $code) {
            User::factory()->create(['name' => 'Invitee ' . $code, 'email' => "invitee-{$code}@coresmith.test", 'password' => $password]);

            $this->failedLatest($this->application("deploy-{$code}", 8100 + $index * 30));
            $this->application("scratch-{$code}", 8110 + $index * 30);
            $this->application("doomed-{$code}", 8120 + $index * 30);

            ManagedDatabase::factory()->postgres()->for($this->team)->for($this->bench)->create(['name' => "doomed_{$code}", 'db_user' => "doomed_{$code}"]);

            $this->server("rename-{$code}", '198.51.100.' . (50 + $index), ServerStatus::Active);
            $this->server("detach-{$code}", '198.51.100.' . (60 + $index), ServerStatus::Active);
            $this->rekeyed("rekeyed-{$code}", '203.0.113.' . (20 + $index));
            $this->rekeyed("dismiss-{$code}", '203.0.113.' . (40 + $index));
        }

        $this->rekeyed('rekeyed-server', '203.0.113.30');
        $this->server('empty-server', '203.0.113.11', ServerStatus::Pending);

        $this->otherTeams($tenant->owner);
    }

    private function otherTeams(User $owner): void
    {
        $acme = Team::factory()->create(['name' => 'Acme Labs', 'slug' => 'acme', 'owner_id' => $owner->id]);
        $acme->members()->attach($owner, ['role' => TeamRole::Admin->value]);

        Server::factory()->for($acme)->create([
            'name' => 'acme-web',
            'environment' => 'Production',
            'region' => 'lon1',
            'ip_address' => '198.51.100.90',
            'status' => ServerStatus::Active,
            'os_name' => 'ubuntu',
            'os_version' => '24.04',
            'provisioning_phase' => 8,
            'last_connected_at' => now(),
        ]);

        TeamDomain::factory()->for($acme)->create(['hostname' => 'panel.acme.test']);
        TeamSsoConnection::factory()->for($acme)->create(['issuer' => 'https://idp.acme.invalid', 'client_id' => 'coresmith-e2e', 'email_domain' => 'acme.test']);

        $northwind = Team::factory()->create(['name' => 'Northwind Ops', 'slug' => 'northwind', 'owner_id' => $owner->id]);
        $northwind->members()->attach($owner, ['role' => TeamRole::Admin->value]);
        TeamDomain::factory()->for($northwind)->verified()->create(['hostname' => 'panel.northwind.test']);
        TeamSsoConnection::factory()->for($northwind)->enabled()->create(['issuer' => 'https://idp.northwind.invalid', 'client_id' => 'coresmith-e2e', 'email_domain' => 'northwind.test']);
        Server::factory()->for($northwind)->create([
            'name' => 'northwind-web',
            'environment' => 'Production',
            'region' => 'fra1',
            'ip_address' => '198.51.100.91',
            'status' => ServerStatus::Active,
            'os_name' => 'ubuntu',
            'os_version' => '24.04',
            'provisioning_phase' => 8,
            'last_connected_at' => now(),
        ]);

        foreach (array_values(self::PROJECTS) as $code) {
            $lab = Team::factory()->create(['name' => 'Lab ' . $code, 'slug' => 'lab-' . $code, 'owner_id' => $owner->id]);
            $lab->members()->attach($owner, ['role' => TeamRole::Owner->value]);
        }
    }

    private function server(string $name, string $ip, ServerStatus $status): Server
    {
        return Server::factory()->for($this->team)->create([
            'name' => $name,
            'environment' => 'Staging',
            'region' => 'ams3',
            'ip_address' => $ip,
            'status' => $status,
            'os_name' => $status === ServerStatus::Active ? 'ubuntu' : null,
            'os_version' => $status === ServerStatus::Active ? '24.04' : null,
            'meta' => $status === ServerStatus::Active ? ['cpu_cores' => 2, 'memory_mb' => 4096, 'disk_gb' => 80] : null,
            'provisioning_phase' => $status === ServerStatus::Active ? 8 : null,
            'host_key_fingerprint' => $status === ServerStatus::Active ? 'SHA256:' . substr(base64_encode(hash('sha256', $name, true)), 0, 43) : null,
            'last_connected_at' => $status === ServerStatus::Active ? now() : null,
        ]);
    }

    private function rekeyed(string $name, string $ip): void
    {
        $this->server($name, $ip, ServerStatus::Active)->forceFill([
            'host_key_fingerprint' => self::ORIGINAL_HOST_KEY,
            'pending_host_key_fingerprint' => self::PRESENTED_HOST_KEY,
            'host_key_changed_at' => now()->subMinutes(9),
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function application(string $name, int $port, array $attributes = []): Application
    {
        return Application::factory()->for($this->team)->for($this->bench)->create([
            'name' => $name,
            'slug' => $name,
            'system_user' => 'coresmith-app-' . $name,
            'type' => ApplicationType::Php,
            'framework' => 'laravel',
            'php_version' => '8.5.1',
            'domain' => $name . '.bench.monoven.io',
            'repository_url' => 'https://github.com/monoventech/' . $name,
            'repository_branch' => 'main',
            'base_port' => $port,
            'status' => ApplicationStatus::Active,
            'env_variables' => "APP_ENV=staging\nDB_PASSWORD=" . bin2hex(random_bytes(12)),
            ...$attributes,
        ]);
    }

    private function rolledBack(Application $application): void
    {
        $older = $this->succeeded($application, 3 * 24 * 60, 'v1.4.0');
        $this->succeeded($application, 2 * 60, 'v1.5.0');

        $application->forceFill(['current_release' => $older])->save();
    }

    private function failedLatest(Application $application): void
    {
        $this->succeeded($application, 2 * 24 * 60, 'v0.9.0');

        $started = CarbonImmutable::now()->subMinutes(50);
        $release = $started->format('Ymd-His');

        Deployment::factory()->for($application)->create([
            'release' => $release,
            'branch' => 'main',
            'commit_hash' => 'c47ab90' . substr(hash('sha1', $application->name), 0, 33),
            'commit_message' => 'Move pricing into its own module',
            'commit_author' => 'Priya Nair',
            'status' => DeploymentStatus::Failed,
            'log' => DemoLog::failed($application, $release, 'main', 'c47ab90', 'Priya Nair', $started),
            'started_at' => $started,
            'finished_at' => $started->addSeconds(108),
            'created_at' => $started,
        ]);
    }

    private function succeeded(Application $application, int $minutesAgo, string $tag): string
    {
        $finished = CarbonImmutable::now()->subMinutes($minutesAgo);
        $started = $finished->subSeconds(96);
        $release = $started->format('Ymd-His');
        $sha = substr(hash('sha1', $application->name . $tag), 0, 7);

        Deployment::factory()->for($application)->create([
            'release' => $release,
            'branch' => 'main',
            'commit_hash' => $sha . substr(hash('sha1', $sha), 0, 33),
            'commit_message' => 'Ship ' . $tag,
            'commit_author' => 'Maya Lindqvist',
            'tag' => $tag,
            'tag_signed' => true,
            'status' => DeploymentStatus::Succeeded,
            'log' => DemoLog::succeeded($application, $release, 'main', $sha, 'Maya Lindqvist', $tag, $started, 96),
            'started_at' => $started,
            'finished_at' => $finished,
            'created_at' => $started,
        ]);

        $application->forceFill(['current_release' => $release])->save();

        return $release;
    }
}
