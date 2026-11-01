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
use App\Enums\SourceProviderType;
use App\Enums\TeamRole;
use App\Jobs\InstallRuntimes;
use App\Models\Application;
use App\Models\CloudProvider;
use App\Models\Deployment;
use App\Models\GitHubApp;
use App\Models\ManagedDatabase;
use App\Models\Server;
use App\Models\SourceProvider;
use App\Models\Team;
use App\Models\TeamDomain;
use App\Models\TeamSsoConnection;
use App\Models\User;
use App\Services\Cloud\CloudCatalog;
use App\Services\Cloud\Tasks\CloudTask;
use App\Services\Deployment\DeploymentPhases;
use App\Services\Git\GitHub\RepositoryCatalog;
use App\Services\Panel\SmartToggles;
use App\Services\Runtime\JsTooling;
use App\Services\Server\ProvisioningLog;
use Carbon\CarbonImmutable;
use Database\Seeders\Support\CloudCatalogs;
use Database\Seeders\Support\DemoLog;
use Database\Seeders\Support\MonovenTenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Spatie\Activitylog\Models\Activity;

final class E2ESeeder extends Seeder
{
    public const OWNER_EMAIL = 'owner@coresmith.test';

    public const ADMIN_EMAIL = 'jonas@coresmith.test';

    public const MEMBER_EMAIL = 'member@coresmith.test';

    public const SIGNOUT_EMAIL = 'signout@coresmith.test';

    public const ROLE_EMAIL = 'rowan@coresmith.test';

    public const ACME_OWNER_EMAIL = 'elin@coresmith.test';

    public const ACME_MEMBER_EMAIL = 'tomas@coresmith.test';

    public const PASSWORD = 'password-for-e2e';

    public const PROJECTS = ['chromium' => 'chr', 'firefox' => 'ffx', 'webkit' => 'wbk', 'mobile-chrome' => 'mch', 'mobile-safari' => 'msf'];

    public const METRICS_AHEAD_MINUTES = 6 * 60;

    public const ORIGINAL_HOST_KEY = 'SHA256:AAAAoriginalkeyfingerprintAAAAAAAAAAAAAAAAAA';

    public const PRESENTED_HOST_KEY = 'SHA256:BBBBimpostorkeyfingerprintBBBBBBBBBBBBBBBBBB';

    private Team $team;

    private Server $bench;

    private SourceProvider $github;

    public function run(): void
    {
        $tenant = (new MonovenTenant(self::METRICS_AHEAD_MINUTES, self::METRICS_AHEAD_MINUTES))->build([
            'owner' => self::OWNER_EMAIL,
            'admin' => self::ADMIN_EMAIL,
            'member' => self::MEMBER_EMAIL,
            'password' => self::PASSWORD,
        ]);

        $this->team = $tenant->team;
        $this->github = $tenant->github;
        $password = Hash::make(self::PASSWORD);

        $this->team->members()->attach(User::factory()->create(['name' => 'Sam Okafor', 'email' => self::SIGNOUT_EMAIL, 'password' => $password]), ['role' => TeamRole::Member->value]);
        $this->team->members()->attach(User::factory()->create(['name' => 'Rowan Hale', 'email' => self::ROLE_EMAIL, 'password' => $password]), ['role' => TeamRole::Member->value]);

        $this->bench = $this->server('bench-01', '198.51.100.40', ServerStatus::Active);

        $hetzner = CloudProvider::factory()->for($this->team)->create(['name' => 'Hetzner Cloud', 'api_token' => 'hetzner-e2e-never-sent']);
        CloudCatalog::put($hetzner, CloudCatalogs::hetzner(), 30 * 24 * 3600);
        $this->cloudServers($hetzner);
        CloudProvider::factory()->linode()->for($this->team)->create(['name' => 'Linode', 'api_token' => 'linode-e2e-never-sent', 'token_failed_at' => now()->subDay()]);

        $this->application('retired-blog', 8000, ['power_state' => PowerState::Stopped]);
        $this->rolledBack($this->application('rolled-back', 8010));
        $this->crashedAfterSwap($this->application('crashy', 8030));
        $this->didNotStart($this->rails('dev-mode', 8300, "RAILS_ENV=development\nSECRET_KEY_BASE=" . bin2hex(random_bytes(16))));
        $this->rollbackDidNotStart($this->rails('failed-rollback', 8310));
        $this->succeeded($this->rails('solid-jobs', 8320), 2 * 24 * 60, 'v1.0.0');
        $this->workerDidNotStart($this->rails('stuck-worker', 8330));
        $this->workerDidNotStartAfterGoingLive($this->rails('no-sidekiq', 8340, attributes: ['processes' => [['name' => 'sidekiq', 'command' => 'bundle exec sidekiq']]]));
        $this->rollbackLeftAWorkerStopped($this->rails('rollback-sidekiq', 8350, attributes: ['processes' => [['name' => 'sidekiq', 'command' => 'bundle exec sidekiq']]]));
        $this->failedClone($this->application('wrong-branch', 8040, ['repository_branch' => 'release/2.0']));
        $this->failedStorageCopy($this->rails('storage-copy', 8360));

        $hungry = $this->application('hungry', 8050, ['app_size' => 'small']);
        $this->succeeded($hungry, 6 * 60, 'v3.1.0');

        activity()
            ->performedOn($hungry)
            ->withProperties(['process' => 'queue', 'at' => now()->subMinutes(40)->toIso8601String(), 'limit_mb' => 256])
            ->log('app_oom_killed');

        $legacy = $this->application('legacy-api', 8060, [
            'type' => ApplicationType::Js,
            'framework' => null,
            'js_runtime' => 'node',
            ...MonovenTenant::stack(['nodejs' => ['20.20.2', '.nvmrc', '20']], null, new JsTooling('npm', true, false, true)),
        ]);
        $this->succeeded($legacy, 3 * 24 * 60, 'v2.3.0');

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

        $this->githubFixtures();

        $this->rekeyed('rekeyed-server', '203.0.113.30');
        $this->server('empty-server', '203.0.113.11', ServerStatus::Pending);
        $this->auditFixtures();

        $this->otherTeams($tenant->owner, $password);
    }

    private function cloudServers(CloudProvider $hetzner): void
    {
        $creating = $this->cloudServer($hetzner, 'creating-server', '4720');
        CloudTask::start($creating, CloudTask::CREATE, 'create', ['image_label' => 'Ubuntu 24.04', 'region_label' => 'Falkenstein (fsn1)']);
        CloudTask::advance($creating, 'boot');
        ProvisioningLog::append($creating, 'creating creating-server at Hetzner Cloud, cx22 in fsn1 with Ubuntu 24.04');
        ProvisioningLog::append($creating->fresh(), 'Hetzner Cloud accepted it as server 4720, waiting for it to boot');

        $failed = $this->cloudServer($hetzner, 'failed-create', '4721', '203.0.113.121');
        CloudTask::start($failed, CloudTask::CREATE, 'create', ['image_label' => 'Ubuntu 24.04', 'region_label' => 'Falkenstein (fsn1)']);
        CloudTask::advance($failed, 'ssh');
        CloudTask::fail($failed, "failed-create is running at 203.0.113.121, but Coresmith couldn't sign in over SSH within 10 minutes. The last error was: SSH authentication failed for root@203.0.113.121. Try again to keep waiting, or destroy it and provision another.");

        $refused = $this->cloudServer($hetzner, 'refused-create', null);
        CloudTask::start($refused, CloudTask::CREATE, 'create', ['image_label' => 'Ubuntu 24.04', 'region_label' => 'Falkenstein (fsn1)']);
        CloudTask::fail($refused, 'Hetzner Cloud refused the request. Hetzner Cloud said: server type cx22 is not available in hel1. Remove this server and provision it again with another size or region.');

        foreach (array_values(self::PROJECTS) as $index => $code) {
            $retry = $this->cloudServer($hetzner, "retry-{$code}", '47' . (30 + $index));
            CloudTask::start($retry, CloudTask::CREATE, 'create', ['image_label' => 'Ubuntu 24.04', 'region_label' => 'Falkenstein (fsn1)']);
            CloudTask::advance($retry, 'boot');
            CloudTask::fail($retry, "Hetzner Cloud didn't finish starting retry-{$code} within 10 minutes. It may still be starting: try again to keep waiting, or destroy it.");
        }
    }

    private function cloudServer(CloudProvider $account, string $name, ?string $instance, ?string $ip = null): Server
    {
        return Server::factory()->fromProvider($account)->creating()->create([
            'name' => $name,
            'environment' => 'Production',
            'ip_address' => $ip,
            'provider_server_id' => $instance,
            'provider_monthly_cost' => '4.35',
            'provider_currency' => 'EUR',
            'host_key_fingerprint' => null,
        ]);
    }

    private function auditFixtures(): void
    {
        $blocked = [
            ['key' => 'port_80', 'severity' => 'blocker', 'title' => 'Port 80 is in use by nginx', 'detail' => 'Caddy needs port 80 to serve your apps over HTTPS. Stop and disable nginx, or let Coresmith do it.', 'fix' => 'stop-nginx'],
            ['key' => 'app_port_8080', 'severity' => 'warning', 'title' => 'Port 8080 is in use by node', 'detail' => 'It is inside the range Coresmith gives apps (8000–9999). Apps skip that port block, or stop the process to free it.', 'fix' => null],
            ['key' => 'mysql', 'severity' => 'info', 'title' => 'MySQL is already running', 'detail' => 'Coresmith creates app databases on it and never reconfigures, restarts or removes it.', 'fix' => null],
            ['key' => 'apparmor', 'severity' => 'info', 'title' => 'AppArmor is on', 'detail' => 'Coresmith works alongside it, nothing to change.', 'fix' => null],
        ];
        $ready = [
            ['key' => 'app_users', 'severity' => 'warning', 'title' => '1 Coresmith app user exists', 'detail' => 'Coresmith may have provisioned this server before. Provisioning reuses those users.', 'fix' => null],
            ['key' => 'ports_free', 'severity' => 'info', 'title' => 'Ports 80 and 443 are free', 'detail' => 'Caddy takes them when provisioning runs.', 'fix' => null],
        ];
        $done = fn(array $findings): array => ['state' => 'done', 'ran_at' => now()->addHours(5)->toIso8601String(), 'findings' => $findings];

        $this->server('audit-server', '203.0.113.60', ServerStatus::Pending)->forceFill(['audit' => $done($blocked)])->save();
        $this->server('checking-server', '203.0.113.61', ServerStatus::Pending)->forceFill(['audit' => ['state' => 'running', 'started_at' => now()->addHours(5)->toIso8601String()]])->save();

        foreach (array_values(self::PROJECTS) as $index => $code) {
            $this->server("blocked-{$code}", '203.0.113.' . (70 + $index), ServerStatus::Pending)->forceFill(['audit' => $done($blocked)])->save();
            $this->server("ready-{$code}", '203.0.113.' . (80 + $index), ServerStatus::Pending)->forceFill(['audit' => $done($ready)])->save();
        }

        $failed = fn(Server $server) => $server->forceFill([
            'status' => ServerStatus::Failed,
            'provisioning_phase' => 3,
            'provisioning_error' => "Directories failed. The command exited with code 1.\nmkdir: cannot create directory '/var/coresmith': Read-only file system",
            'provisioning_log' => "[09:12:01] phase 1 of 8, Operating system: started\n[09:12:03] phase 1 of 8, Operating system: done\n[09:12:03] phase 2 of 8, Base system: started\n[09:14:40] phase 2 of 8, Base system: done\n[09:14:40] phase 3 of 8, Runtimes: started\n[09:16:02] phase 3 of 8, Runtimes: done\n[09:16:02] phase 4 of 8, Directories: started\n[09:16:03] phase 4 of 8, Directories: failed. SSH command failed with exit code 1.",
        ])->save();

        $failed($this->server('halfway-server', '203.0.113.62', ServerStatus::Pending));

        foreach (array_values(self::PROJECTS) as $index => $code) {
            $failed($this->server("stalled-{$code}", '203.0.113.' . (90 + $index), ServerStatus::Pending));
        }
    }

    private function githubFixtures(): void
    {
        $this->repositoryCatalog();

        $this->linkedApplication('linked-app', 8070);
        $this->linkedApplication('pushing-app', 8080, ['quick_deploy' => true, 'polled_at' => now()->subSeconds(30)]);
        $this->linkedApplication('orphaned-app', 8090, ['source_provider_id' => $this->removedConnection()->id]);

        foreach (array_values(self::PROJECTS) as $index => $code) {
            $this->linkedApplication("linked-{$code}", 8400 + $index * 10);
        }
    }

    private function repositoryCatalog(): void
    {
        $listing = [
            ['70000001', 'monoventech/atlas', true, 'main', ['main', 'dev']],
            ['70000002', 'monoventech/ledger', true, 'main', ['main', 'dev']],
            ['70000003', 'monoventech/relay', true, 'trunk', ['trunk', 'release/2.0']],
            ['70000004', 'monoventech/storefront', false, 'main', ['main', 'dev']],
        ];
        $expires = now()->addYear();
        $repositories = [];

        foreach ($listing as $position => [$id, $fullName, $private, $default, $branches]) {
            $repositories[] = [
                'id' => $id,
                'full_name' => $fullName,
                'private' => $private,
                'default_branch' => $default,
                'archived' => false,
                'pushed_at' => now()->subHours(($position + 1) * 18)->toIso8601ZuluString(),
            ];

            Cache::put(RepositoryCatalog::branchesKey($this->github, $id), $branches, $expires);
        }

        Cache::put(RepositoryCatalog::key($this->github), ['total' => count($repositories), 'truncated' => false, 'repositories' => $repositories], $expires);
    }

    private function removedConnection(): SourceProvider
    {
        $connection = SourceProvider::factory()->for($this->team)->create([
            'github_app_id' => $this->github->github_app_id,
            'name' => 'monoven-labs',
            'account_type' => 'Organization',
            'repository_selection' => 'selected',
            'installation_id' => '51843',
        ]);

        $connection->disconnect();

        return $connection;
    }

    private function otherTeams(User $owner, string $password): void
    {
        $acme = Team::factory()->create(['name' => 'Acme Labs', 'slug' => 'acme', 'owner_id' => $owner->id]);
        $acme->members()->attach($owner, ['role' => TeamRole::Admin->value]);
        $acme->members()->attach(User::factory()->create(['name' => 'Elin Larsen', 'email' => self::ACME_OWNER_EMAIL, 'password' => $password]), ['role' => TeamRole::Owner->value]);
        $acme->members()->attach(User::factory()->create(['name' => 'Tomas Novak', 'email' => self::ACME_MEMBER_EMAIL, 'password' => $password]), ['role' => TeamRole::Member->value]);

        $acmeWeb = Server::factory()->for($acme)->create([
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

        $portal = Application::factory()->for($acme)->for($acmeWeb)->create([
            'name' => 'acme-portal',
            'slug' => 'acme-portal',
            'system_user' => 'coresmith-app-acme-portal',
            'type' => ApplicationType::Php,
            'framework' => 'laravel',
            'php_version' => null,
            'domain' => 'portal.acme.test',
            'repository_url' => 'https://github.com/acme/portal',
            'repository_branch' => 'main',
            'base_port' => 8000,
            'status' => ApplicationStatus::Deploying,
        ]);

        $started = CarbonImmutable::now()->subMinutes(3);
        $release = $started->format('Ymd-His');

        Deployment::factory()->for($portal)->create([
            'release' => $release,
            'branch' => 'main',
            'commit_hash' => 'a1b2c3d' . substr(hash('sha1', 'acme-portal'), 0, 33),
            'commit_message' => 'Move to PHP 8.5',
            'commit_author' => 'Maya Lindqvist',
            'status' => DeploymentStatus::Running,
            'started_at' => $started,
            'stale_after' => $started->addSeconds(InstallRuntimes::window()),
            'created_at' => $started,
            'stack' => MonovenTenant::stack(['php' => ['8.5.10', 'composer.json', '^8.5']], ['name' => 'laravel', 'major' => 13])['stack'],
            'log' => implode("\n", [
                '[' . $started->format('H:i:s') . '] release ' . $release . ' · branch main · triggered by Maya Lindqvist',
                '[' . $started->addSeconds(1)->format('H:i:s') . '] clone: fetching over https',
                'commit a1b2c3d by Maya Lindqvist',
                '[' . $started->addSeconds(9)->format('H:i:s') . '] deps: resolving runtimes from the repository',
                'PHP 8.5.10 from composer.json (^8.5), active until Dec 2027, not installed yet',
                '[' . $started->addSeconds(10)->format('H:i:s') . '] deps: installing PHP 8.5.10, compiling it, usually 5–15 minutes the first time',
            ]),
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

        foreach (array_values(self::PROJECTS) as $index => $code) {
            $lab = Team::factory()->create(['name' => 'Lab ' . $code, 'slug' => 'lab-' . $code, 'owner_id' => $owner->id]);
            $lab->members()->attach($owner, ['role' => TeamRole::Owner->value]);
            $this->registeredApp($lab, $owner, "lab-{$code}", (string) (1041300 + $index));
            CloudProvider::factory()->vultr()->for($lab)->create(['name' => 'Spare', 'api_token' => "vultr-{$code}-never-sent"]);
        }

        $paused = Team::factory()->create(['name' => 'Paused Works', 'slug' => 'paused', 'owner_id' => $owner->id]);
        $paused->members()->attach($owner, ['role' => TeamRole::Owner->value]);
        SourceProvider::factory()->for($paused)->githubApp($this->registeredApp($paused, $owner, 'paused-works', '1041310'))->create([
            'name' => 'paused-works',
            'account_type' => 'Organization',
            'repository_selection' => 'all',
            'installation_id' => '51844',
            'suspended_at' => now()->subDay(),
            'access_token' => null,
            'token_expires_at' => null,
        ]);

        $tidewater = Team::factory()->create(['name' => 'Tidewater Ops', 'slug' => 'tidewater', 'owner_id' => $owner->id]);
        $tidewater->members()->attach($owner, ['role' => TeamRole::Owner->value]);
        SourceProvider::factory()->for($tidewater)->create([
            'type' => SourceProviderType::GitLab->value,
            'name' => 'tidewater-ops',
            'installation_id' => null,
            'scopes' => SourceProviderType::GitLab->scopes(),
        ]);
    }

    private function registeredApp(Team $team, User $owner, string $login, string $appId): GitHubApp
    {
        return GitHubApp::factory()->withoutWebhook()->for($team)->create([
            'app_id' => $appId,
            'slug' => "coresmith-{$login}",
            'name' => "Coresmith {$login}",
            'owner_login' => $login,
            'owner_type' => 'Organization',
            'html_url' => "https://github.com/apps/coresmith-{$login}",
            'created_by' => $owner->id,
        ]);
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
            ...MonovenTenant::stack(['php' => ['8.5.10', 'composer.json', '^8.4']], ['name' => 'laravel', 'major' => 13]),
            'domain' => $name . '.bench.monoven.io',
            'repository_url' => 'https://github.com/monoventech/' . $name,
            'repository_branch' => 'main',
            'base_port' => $port,
            'status' => ApplicationStatus::Active,
            'env_variables' => "APP_ENV=staging\nDB_PASSWORD=" . bin2hex(random_bytes(12)),
            ...$attributes,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function linkedApplication(string $name, int $port, array $attributes = []): Application
    {
        return $this->application($name, $port, [
            'source_provider_id' => $this->github->id,
            'repository_id' => '70000003',
            'repository_url' => 'https://github.com/monoventech/relay.git',
            'repository_provider' => 'github',
            'repository_branch' => 'trunk',
            ...$attributes,
        ]);
    }

    private function rolledBack(Application $application): void
    {
        $older = $this->succeeded($application, 3 * 24 * 60, 'v1.4.0');
        $newer = $this->succeeded($application, 2 * 60, 'v1.5.0');

        $this->logAt($application, 'application_rolled_back', ['from' => $newer, 'to' => $older, 'runtimes' => 'unchanged'], CarbonImmutable::now()->subMinutes(90));

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

    private function crashedAfterSwap(Application $application): void
    {
        $this->succeeded($application, 3 * 60, 'v2.3.0');

        $started = CarbonImmutable::now()->subMinutes(20);
        $release = $started->format('Ymd-His');
        $clock = fn(int $seconds): string => '[' . $started->addSeconds($seconds)->format('H:i:s') . ']';
        $reason = "Crash loop: coresmith-app-crashy-fpm kept stopping in the minute after going live. releases/{$release} is live but not serving, so roll back to the previous release or fix the error in the log.";

        Deployment::factory()->for($application)->create([
            'release' => $release,
            'branch' => 'main',
            'commit_hash' => 'd41c0de' . substr(hash('sha1', 'crashy'), 0, 33),
            'commit_message' => 'Swap the payment gateway',
            'commit_author' => 'Priya Nair',
            'status' => DeploymentStatus::Failed,
            'failure_reason' => $reason,
            'log' => implode("\n", [
                $clock(0) . " release {$release} · branch main · triggered by push",
                $clock(1) . ' clone: fetching over https',
                $clock(9) . ' deps: installing dependencies',
                $clock(41) . ' build: building the release',
                $clock(55) . ' health: checking the release before it goes live',
                'health: index.php present',
                $clock(56) . " swap: current → releases/{$release}",
                "symlink: current → releases/{$release} (atomic swap)",
                $clock(60) . ' watch: keeping an eye on the processes for 60 seconds',
                'PHP Fatal error:  Uncaught Error: Class "App\\Billing\\Gateway" not found in /var/coresmith/apps/crashy/current/app/Providers/AppServiceProvider.php:31',
                $reason,
                $clock(91) . " deploy failed after the symlink swap. releases/{$release} is live, check its processes.",
            ]),
            'started_at' => $started,
            'finished_at' => $started->addSeconds(91),
            'created_at' => $started,
        ]);

        $application->forceFill(['current_release' => $release, 'status' => ApplicationStatus::Failed])->save();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function rails(string $name, int $port, ?string $env = null, array $attributes = []): Application
    {
        return $this->application($name, $port, [
            'type' => ApplicationType::Ruby,
            'framework' => 'rails',
            'php_version' => null,
            ...MonovenTenant::stack(['ruby' => ['3.4.10', '.ruby-version', '3.4.10']], ['name' => 'rails', 'major' => 8], null, ['sidekiq' => false, 'solid_queue' => true]),
            'env_variables' => $env ?? "RAILS_ENV=production\nSECRET_KEY_BASE=" . bin2hex(random_bytes(16)),
            ...$attributes,
        ]);
    }

    private function didNotStart(Application $application): void
    {
        $this->succeeded($application, 26 * 60, 'v1.2.0');

        $started = CarbonImmutable::now()->subMinutes(20);
        $gems = "/var/coresmith/apps/{$application->slug}/releases/" . $started->format('Ymd-His') . '/vendor/bundle/ruby/3.4.0/gems';

        $release = $this->wentLiveButDidNotStart($application, $started, '5e1d0a2', 'Add the stock alerts mailer', 'web', [
            "{$gems}/bootsnap-1.18.4/lib/bootsnap/load_path_cache/core_ext/kernel_require.rb:30:in 'Kernel#require': cannot load such file -- debug/prelude (LoadError)",
            "\tfrom {$gems}/railties-8.0.2/lib/rails/initializable.rb:32:in 'Rails::Initializable::Initializer#run'",
        ], 'This Rails app runs with RAILS_ENV=development, but its gems were installed for production only. Set RAILS_ENV=production in its environment variables, then deploy again.');

        $application->forceFill(['current_release' => $release, 'status' => ApplicationStatus::Failed])->save();
    }

    private function rollbackDidNotStart(Application $application): void
    {
        $healthy = $this->succeeded($application, 9 * 60, 'v2.0.0');
        $port = $application->base_port;
        $advice = "Port {$port} is already taken by another process. Find it with ss -tlnp | grep {$port}, stop it, then deploy again.";

        $broken = $this->wentLiveButDidNotStart($application, CarbonImmutable::now()->subHours(5), '9b0c3f1', 'Serve the reports from Puma', 'web', [
            "Address already in use - bind(2) for \"127.0.0.1\" port {$port} (Errno::EADDRINUSE)",
        ], $advice);

        $this->logAt($application, 'rollback_processes_failed', [
            'from' => $broken,
            'to' => $healthy,
            'error' => "coresmith-app-{$application->slug}-web did not start, systemd reports it as activating. {$advice}",
        ], CarbonImmutable::now()->subHours(4));

        $application->forceFill(['current_release' => $healthy, 'status' => ApplicationStatus::Failed])->save();
    }

    private function rollbackLeftAWorkerStopped(Application $application): void
    {
        $older = $this->succeeded($application, 7 * 60, 'v1.0.0');
        $newer = $this->succeeded($application, 4 * 60, 'v1.1.0');

        $this->logAt($application, 'rollback_worker_failed', [
            'from' => $newer,
            'to' => $older,
            'worker' => 'Sidekiq',
            'error' => "coresmith-app-{$application->slug}-sidekiq did not start, systemd reports it as activating. The process log says why. Fix it and deploy again, or roll back to the previous release.",
        ], CarbonImmutable::now()->subHours(3));

        $application->forceFill(['current_release' => $older])->save();
    }

    private function workerDidNotStartAfterGoingLive(Application $application): void
    {
        $this->succeeded($application, 30 * 60, 'v3.0.0');

        $release = $this->wentLiveButDidNotStart($application, CarbonImmutable::now()->subMinutes(45), 'c0ffee1', 'Add the nightly export', 'sidekiq', [
            'bundler: command not found: sidekiq',
            'Install missing gem executables with `bundle install`',
        ], 'The process log says why. Fix it and deploy again, or roll back to the previous release.');

        $application->forceFill(['current_release' => $release])->save();
    }

    /**
     * @param  list<string>  $output
     */
    private function wentLiveButDidNotStart(Application $application, CarbonImmutable $started, string $sha, string $message, string $process, array $output, string $advice): string
    {
        $release = $started->format('Ymd-His');
        $clock = fn(int $seconds): string => '[' . $started->addSeconds($seconds)->format('H:i:s') . ']';
        $unit = 'coresmith-app-' . $application->slug . '-' . $process;
        $units = ['coresmith-app-' . $application->slug . '-web', ...array_map(fn(array $worker): string => 'coresmith-app-' . $application->slug . '-' . $worker['name'], (array) $application->processes)];
        $reason = "{$unit} did not start, systemd reports it as activating. {$advice}";

        Deployment::factory()->for($application)->create([
            'release' => $release,
            'branch' => 'main',
            'commit_hash' => $sha . substr(hash('sha1', $application->name . $sha), 0, 33),
            'commit_message' => $message,
            'commit_author' => 'Priya Nair',
            'status' => DeploymentStatus::Failed,
            'failure_reason' => $reason,
            'start_failed' => true,
            'failed_worker' => app(SmartToggles::class)->failedWorker($application, $unit),
            'log' => implode("\n", [
                $clock(0) . " release {$release} · branch main · triggered by push",
                $clock(1) . ' clone: fetching over https',
                "commit {$sha} by Priya Nair",
                $clock(9) . ' deps: installing dependencies',
                '$ bundle install',
                'Bundle complete! 23 Gemfile dependencies, 91 gems now installed.',
                $clock(38) . ' build: building the release',
                '$ RAILS_ENV=production bundle exec rails assets:precompile',
                $clock(51) . " migrate: running migrations against release {$release}",
                '$ RAILS_ENV=production bundle exec rails db:migrate',
                $clock(54) . ' health: checking the release before it goes live',
                'health: Gemfile present',
                $clock(55) . " swap: current → releases/{$release}",
                "symlink: current → releases/{$release} (atomic swap)",
                $clock(55) . ' watch: starting ' . implode(', ', $units),
                "{$unit}.service: Main process exited, code=exited, status=1/FAILURE",
                ...$output,
                $reason,
                $clock(61) . " deploy failed after the symlink swap. releases/{$release} is live, check its processes.",
            ]),
            'started_at' => $started,
            'finished_at' => $started->addSeconds(61),
            'created_at' => $started,
        ]);

        return $release;
    }

    private function workerDidNotStart(Application $application): void
    {
        $this->succeeded($application, 3 * 24 * 60, 'v0.4.0');

        $error = "coresmith-app-{$application->slug}-jobs did not start, systemd reports it as activating. The entry point was not found. Check the build produced the file the process starts, then deploy again.";

        $this->logAt($application, 'toggle_changed', ['toggle' => 'solid_queue', 'label' => 'Solid Queue', 'on' => true], CarbonImmutable::now()->subMinutes(35));
        $this->logAt($application, 'toggle_start_failed', ['toggle' => 'solid_queue', 'label' => 'Solid Queue', 'error' => $error], CarbonImmutable::now()->subMinutes(34));
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function logAt(Application $application, string $description, array $properties, CarbonImmutable $at): void
    {
        $activity = activity()->performedOn($application)->withProperties($properties)->log($description);

        if ($activity instanceof Activity) {
            $activity->forceFill(['created_at' => $at, 'updated_at' => $at])->save();
        }
    }

    private function failedClone(Application $application): void
    {
        $this->succeeded($application, 26 * 60, 'v1.0.0');

        $started = CarbonImmutable::now()->subMinutes(35);
        $reason = "Branch release/2.0 is not in the repository. Check the branch name in the app's settings, then deploy again.";

        Deployment::factory()->for($application)->create([
            'release' => $started->format('Ymd-His'),
            'branch' => 'release/2.0',
            'status' => DeploymentStatus::Failed,
            'failure_reason' => $reason,
            'log' => implode("\n", [
                '[' . $started->format('H:i:s') . '] release ' . $started->format('Ymd-His') . ' · branch release/2.0 · triggered by push',
                '[' . $started->addSecond()->format('H:i:s') . '] clone: fetching over https',
                'fatal: not a valid object name: release/2.0',
                $reason,
                '[' . $started->addSeconds(4)->format('H:i:s') . '] deploy failed. the current release is untouched.',
            ]),
            'started_at' => $started,
            'finished_at' => $started->addSeconds(4),
            'created_at' => $started,
        ]);
    }

    private function failedStorageCopy(Application $application): void
    {
        $this->succeeded($application, 3 * 60, 'v1.4.0');

        $started = CarbonImmutable::now()->subMinutes(25);
        $release = $started->format('Ymd-His');
        $sha = substr(hash('sha1', $application->name . 'uploads'), 0, 7);

        Deployment::factory()->for($application)->create([
            'release' => $release,
            'branch' => 'main',
            'commit_hash' => $sha . substr(hash('sha1', $sha), 0, 33),
            'commit_message' => 'Keep uploads in Active Storage',
            'commit_author' => 'Priya Nair',
            'status' => DeploymentStatus::Failed,
            'failed_step' => DeploymentPhases::STORAGE,
            'log' => implode("\n", [
                '[' . $started->format('H:i:s') . "] release {$release} · branch main · triggered by push",
                '[' . $started->addSecond()->format('H:i:s') . '] clone: fetching over https',
                "commit {$sha} by Priya Nair",
                'Error: file is not a database',
                "Could not copy /var/coresmith/apps/{$application->slug}/current/storage/production.sqlite3.",
                "Could not copy the live release's storage into shared/storage, so nothing was changed. Fix the error above, then deploy again.",
                '[' . $started->addSeconds(6)->format('H:i:s') . '] deploy failed. the current release is untouched.',
            ]),
            'started_at' => $started,
            'finished_at' => $started->addSeconds(6),
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
