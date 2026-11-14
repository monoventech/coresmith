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
use App\Exceptions\ProcessDidNotStart;
use App\Jobs\InstallRuntimes;
use App\Jobs\ReadSchedulerLog;
use App\Models\Application;
use App\Models\CloudProvider;
use App\Models\Deployment;
use App\Models\GitHubApp;
use App\Models\ManagedDatabase;
use App\Models\Server;
use App\Models\ServerMetric;
use App\Models\SourceProvider;
use App\Models\SshKey;
use App\Models\Team;
use App\Models\TeamDomain;
use App\Models\TeamInvitation;
use App\Models\TeamSsoConnection;
use App\Models\User;
use App\Services\Cloud\CloudCatalog;
use App\Services\Cloud\Tasks\CloudTask;
use App\Services\Deployment\DeploymentPhases;
use App\Services\Git\GitHub\RepositoryCatalog;
use App\Services\Moves\MoveCodes;
use App\Services\Moves\MoveFile;
use App\Services\Panel\CronJobs;
use App\Services\Panel\SmartToggles;
use App\Services\Runtime\JsTooling;
use App\Services\Server\Audit\PreProvisioningAudit;
use App\Services\Server\CertificateCheck;
use App\Services\Server\PortAllocator;
use App\Services\Server\ProvisioningLog;
use App\Services\Server\SchedulerLogReader;
use App\Services\Server\ServerReachability;
use App\Services\SSH\HostKeyFingerprint;
use App\Services\SSH\KeyPairGenerator;
use App\Services\Teams\TeamAccess;
use App\Services\Updates\ReleaseLayout;
use App\Services\Updates\UpdatePaths;
use Carbon\CarbonImmutable;
use Database\Seeders\Support\CloudCatalogs;
use Database\Seeders\Support\DemoLog;
use Database\Seeders\Support\MonovenTenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use phpseclib3\Exception\UnableToConnectException;
use Spatie\Activitylog\Models\Activity;

final class E2ESeeder extends Seeder
{
    public const OWNER_EMAIL = 'owner@coresmith.test';

    public const ADMIN_EMAIL = 'jonas@coresmith.test';

    public const MEMBER_EMAIL = 'member@coresmith.test';

    public const SIGNOUT_EMAIL = 'signout@coresmith.test';

    public const ROLE_EMAIL = 'rowan@coresmith.test';

    public const NARROWED_EMAIL = 'narrowed@coresmith.test';

    public const ENVIRONMENT_EMAIL = 'environment@coresmith.test';

    public const DEVELOPER_EMAIL = 'developer@coresmith.test';

    public const ACME_OWNER_EMAIL = 'elin@coresmith.test';

    public const ACME_MEMBER_EMAIL = 'tomas@coresmith.test';

    public const WAITING_INVITATION_TOKEN = 'e2eWaitingInvitation00000000000000000000';

    public const EXPIRED_INVITATION_TOKEN = 'e2eExpiredInvitation00000000000000000000';

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

        $narrowed = User::factory()->create(['name' => 'Noor Haddad', 'email' => self::NARROWED_EMAIL, 'password' => $password]);
        $this->team->members()->attach($narrowed, ['role' => TeamRole::Admin->value, 'sees_everything' => false]);
        app(TeamAccess::class)->grant($this->team, $narrowed, $tenant->servers['forge-01']);
        app(TeamAccess::class)->grant($this->team, $narrowed, $tenant->applications['warehouse']);
        SourceProvider::factory()->githubApp(GitHubApp::factory()->create(['team_id' => $this->team->id, 'user_id' => $narrowed->id, 'name' => 'Coresmith for Noor Haddad']))->create(['team_id' => $this->team->id, 'user_id' => $narrowed->id, 'name' => 'noor-h']);

        $environmentOnly = User::factory()->create(['name' => 'Ines Duarte', 'email' => self::ENVIRONMENT_EMAIL, 'password' => $password]);
        $this->team->members()->attach($environmentOnly, ['role' => TeamRole::Admin->value, 'sees_everything' => false]);
        app(TeamAccess::class)->grant($this->team, $environmentOnly, $this->team->projects()->where('name', 'Storefront')->sole()->environments()->where('name', 'Staging')->sole());

        $developer = User::factory()->create(['name' => 'Lena Brandt', 'email' => self::DEVELOPER_EMAIL, 'password' => $password]);
        $this->team->members()->attach($developer, ['role' => TeamRole::Developer->value, 'sees_everything' => false]);
        app(TeamAccess::class)->grant($this->team, $developer, $tenant->servers['forge-02']);
        $this->application('side-notes', 9620, ['created_by' => $developer->id, 'domain' => 'side-notes.lena.dev'], $tenant->servers['forge-02']);
        $this->server('lena-01', '198.51.100.44', ServerStatus::Active)->forceFill(['created_by' => $developer->id])->save();
        $lenaKey = (new KeyPairGenerator)->generate()->publicKey;
        SshKey::factory()->for($developer)->create(['name' => 'lena@laptop', 'public_key' => $lenaKey, 'fingerprint' => (string) HostKeyFingerprint::fromPublicKey($lenaKey)]);

        $this->movers();

        $this->bench = $this->server('bench-01', '198.51.100.40', ServerStatus::Active);

        $hetzner = CloudProvider::factory()->for($this->team)->create(['name' => 'Hetzner Cloud', 'api_token' => 'hetzner-e2e-never-sent']);
        CloudCatalog::put($hetzner, CloudCatalogs::hetzner(), 30 * 24 * 3600);
        $this->cloudServers($hetzner);
        CloudProvider::factory()->linode()->for($this->team)->create(['name' => 'Linode', 'api_token' => 'linode-e2e-never-sent', 'token_failed_at' => now()->subDay()]);

        $this->application('retired-blog', 8000, ['power_state' => PowerState::Stopped]);
        $this->rolledBack($this->application('rolled-back', 8010));
        $this->heldByAQueuedDeploy($this->application('held-app', 9190));
        $this->realtime('reverb-unset', 9170);
        $this->releaseMovedOn($this->realtime('release-moved-on', 9210));
        $this->cancelFixtures($tenant->owner);
        $this->certificatesChecked($this->application('certs-app', 9180, [
            'domain' => 'certs.monoven.io',
            'additional_domains' => ['soon.monoven.io', 'gone.monoven.io', 'dark.monoven.io'],
        ]));
        $this->crashedAfterSwap($this->application('crashy', 8030));
        $this->didNotStart($this->rails('dev-mode', 8300, "RAILS_ENV=development\nSECRET_KEY_BASE=" . bin2hex(random_bytes(16))));
        $this->rollbackDidNotStart($this->rails('failed-rollback', 8310));
        $this->succeeded($this->rails('solid-jobs', 8320), 2 * 24 * 60, 'v1.0.0');
        $this->workerDidNotStart($this->rails('stuck-worker', 8330));
        $this->workerDidNotStartAfterGoingLive($this->rails('no-sidekiq', 8340, attributes: ['processes' => [['name' => 'sidekiq', 'command' => 'bundle exec sidekiq']]]));
        $this->rollbackLeftAWorkerStopped($this->rails('rollback-sidekiq', 8350, attributes: ['processes' => [['name' => 'sidekiq', 'command' => 'bundle exec sidekiq']]]));
        $this->failedClone($this->application('wrong-branch', 8040, ['repository_branch' => 'release/2.0']));
        $this->failedStorageCopy($this->rails('storage-copy', 8360));
        $this->succeeded($this->python('celery-tasks', 8370), 3 * 60, 'v0.4.0');

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
            $this->team->members()->attach(User::factory()->create(['name' => 'Narrow ' . $code, 'email' => "narrow-{$code}@coresmith.test", 'password' => $password]), ['role' => TeamRole::Member->value]);

            $newPassword = User::factory()->create(['name' => 'New password ' . $code, 'email' => "new-password-{$code}@coresmith.test", 'password' => $password]);
            $newPasswordTeam = Team::factory()->create(['name' => 'New password ' . $code, 'slug' => 'new-password-' . $code, 'owner_id' => $newPassword->id]);
            $newPasswordTeam->members()->attach($newPassword, ['role' => TeamRole::Owner->value]);
            $newPassword->forceFill(['current_team_id' => $newPasswordTeam->id])->save();

            $this->failedLatest($this->application("deploy-{$code}", 8100 + $index * 30));
            $this->application("scratch-{$code}", 8110 + $index * 30);
            $this->application("doomed-{$code}", 8120 + $index * 30);
            $this->application("filed-{$code}", 9400 + $index * 10);

            ManagedDatabase::factory()->redis()->for($this->team)->for($this->bench)->create(['name' => "doomed_{$code}", 'db_user' => "doomed_{$code}"]);
            ManagedDatabase::factory()->for($this->team)->for($this->bench)->create(['name' => "reveal_{$code}", 'db_user' => "reveal_{$code}"]);

            $this->server("rename-{$code}", '198.51.100.' . (50 + $index), ServerStatus::Active);
            $this->server("label-{$code}", '198.51.100.' . (110 + $index), ServerStatus::Active)->forceFill(['environment' => null])->save();
            $this->server("detach-{$code}", '127.0.0.1', ServerStatus::Active)->forceFill(['ssh_port' => 1])->save();
            $this->rekeyed("rekeyed-{$code}", '203.0.113.' . (20 + $index));
            $this->rekeyed("dismiss-{$code}", '203.0.113.' . (40 + $index));
            $this->application("busy-{$code}", 9200 + $index * 10);
            $held = $this->application("held-{$code}", 9500 + $index * 10, ['current_release' => '20261112-091400', ...MonovenTenant::stack(['php' => ['8.4.20', 'composer.json', '^8.2']], ['name' => 'laravel', 'major' => 13])]);
            Deployment::factory()->for($held)->create([
                'release' => '20261112-091400',
                'status' => DeploymentStatus::Succeeded,
                'stack' => ['runtimes' => ['php' => ['runtime' => 'php', 'version' => '8.4.20', 'source' => 'composer.json', 'detail' => '^8.2', 'held_by' => ['nette/schema 1.3.2', 'nette/utils 4.0.5'], 'newest' => '8.5.10']]],
            ]);
            $this->server("live-{$code}", '198.51.100.' . (80 + $index), ServerStatus::Active);
            $this->realtime("realtime-{$code}", 9120 + $index * 10, [
                'env_variables' => "APP_ENV=staging\nREVERB_APP_ID=realtime-{$code}\nREVERB_APP_KEY=key-{$code}\nREVERB_APP_SECRET=" . bin2hex(random_bytes(16)),
                'processes' => [['name' => 'queue', 'command' => SmartToggles::HORIZON_COMMAND]],
            ]);
        }

        $this->githubFixtures($tenant->applications['atlas']);
        $this->domainReach($tenant->applications['atlas']);
        $this->repositoryChecks();
        $this->answered500();
        $this->cronFixtures();

        $this->rekeyed('rekeyed-server', '203.0.113.30');
        $this->unreachable('down-01', '198.51.100.70');
        $this->shortOfMemory('hot-01', '198.51.100.71');
        $this->ssrDidNotStart($this->realtime('ssr-failed', 9220, [], Server::query()->where('name', 'hot-01')->sole()));
        $this->server('empty-server', '203.0.113.11', ServerStatus::Pending);
        $this->connectFixtures();
        $this->databasePasswordFixtures();
        $this->serverWithNoFreePorts();
        $this->auditFixtures();
        $this->invitations($tenant->owner);

        $this->otherTeams($tenant->owner, $password);
        $this->connectionWithoutItsApp($tenant->owner);

        activity()
            ->withProperties(['from' => '0.1.0', 'to' => '0.1.1', 'trigger' => 'auto', 'duration_seconds' => 94, 'signature' => 'unchecked'])
            ->createdAt(now()->addMinutes(self::METRICS_AHEAD_MINUTES))
            ->log('panel_updated');

        $this->managedPanel();
    }

    private function managedPanel(): void
    {
        $paths = UpdatePaths::fromConfig();

        File::deleteDirectory($paths->root());
        File::deleteDirectory($paths->state());
        File::ensureDirectoryExists($paths->releases() . '/0.1.0-0000000/public');
        File::ensureDirectoryExists($paths->shared() . '/storage');
        File::put($paths->shared() . '/.env', "APP_ENV=e2e\n");

        $release = app(ReleaseLayout::class)->seal($paths->releases() . '/0.1.0-0000000', [
            'version' => '0.1.0',
            'commit' => '0000000',
            'tag' => 'v0.1.0',
            'signature' => 'unchecked',
            'fingerprint' => null,
            'built_by' => 'installer',
        ]);

        symlink($release->path, $paths->current());
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

        $lost = $this->cloudServer($hetzner, 'lost-create', null);
        CloudTask::start($lost, CloudTask::CREATE, 'create', ['image_label' => 'Ubuntu 24.04', 'region_label' => 'Falkenstein (fsn1)', 'uncertain' => 3]);
        CloudTask::fail($lost, "Coresmith couldn't get an answer from Hetzner Cloud after three tries. Check the Hetzner Cloud console for a server called lost-create: if there is one, delete it there, then remove this server and provision it again.");

        $refused = $this->cloudServer($hetzner, 'refused-create', null);
        CloudTask::start($refused, CloudTask::CREATE, 'create', ['image_label' => 'Ubuntu 24.04', 'region_label' => 'Falkenstein (fsn1)']);
        CloudTask::fail($refused, 'Hetzner Cloud refused the request. Hetzner Cloud said: server type cx22 is not available in hel1. Remove this server and provision it again with another size or region.');

        foreach (array_values(self::PROJECTS) as $index => $code) {
            Server::factory()->fromProvider($hetzner)->create([
                'name' => "destroy-{$code}",
                'environment' => 'Production',
                'ip_address' => '203.0.113.' . (140 + $index),
                'status' => ServerStatus::Active,
                'provider_server_id' => "48{$index}0",
                'provider_monthly_cost' => '3.79',
                'provider_currency' => 'EUR',
                'meta' => ['cpu_cores' => 2, 'memory_mb' => 4096, 'disk_gb' => 40],
                'host_key_fingerprint' => null,
            ]);
        }

        $protected = Server::factory()->fromProvider($hetzner)->create([
            'name' => 'destroy-failed',
            'environment' => 'Production',
            'ip_address' => '203.0.113.139',
            'status' => ServerStatus::Active,
            'provider_server_id' => '4799',
            'provider_monthly_cost' => '3.79',
            'provider_currency' => 'EUR',
            'host_key_fingerprint' => null,
        ]);
        CloudTask::start($protected, CloudTask::DESTROY, 'delete');
        CloudTask::fail($protected, "Hetzner Cloud refused the request. Hetzner Cloud said: server is protected. Try again once that's fixed, or delete it in the Hetzner Cloud console and then detach it here.");

        $this->destroying($hetzner, 'destroying-server', '4797', '203.0.113.138');

        foreach (array_values(self::PROJECTS) as $index => $code) {
            $this->destroying($hetzner, "gone-{$code}", '48' . (50 + $index), '203.0.113.' . (160 + $index));
        }

        foreach (array_values(self::PROJECTS) as $index => $code) {
            Server::factory()->fromProvider($hetzner)->create([
                'name' => "resize-{$code}",
                'environment' => 'Production',
                'ip_address' => '203.0.113.' . (150 + $index),
                'status' => ServerStatus::Active,
                'provider_server_id' => "49{$index}0",
                'provider_monthly_cost' => '3.79',
                'provider_currency' => 'EUR',
                'meta' => ['cpu_cores' => 2, 'memory_mb' => 4096, 'disk_gb' => 40],
                'host_key_fingerprint' => null,
            ]);
        }

        $stuck = Server::factory()->fromProvider($hetzner)->create([
            'name' => 'resize-failed',
            'environment' => 'Production',
            'ip_address' => '203.0.113.159',
            'status' => ServerStatus::Active,
            'provider_server_id' => '4998',
            'provider_monthly_cost' => '3.79',
            'provider_currency' => 'EUR',
            'meta' => ['cpu_cores' => 2, 'memory_mb' => 4096, 'disk_gb' => 40],
            'host_key_fingerprint' => null,
        ]);
        CloudTask::start($stuck, CloudTask::RESIZE, 'change', ['from' => 'cx22', 'to' => 'cx32', 'power_cycle' => true]);
        CloudTask::fail($stuck, 'Hetzner Cloud refused the request. Hetzner Cloud said: no capacity. resize-failed was started again on cx22.');

        foreach (array_values(self::PROJECTS) as $index => $code) {
            $retry = $this->cloudServer($hetzner, "retry-{$code}", '47' . (30 + $index));
            CloudTask::start($retry, CloudTask::CREATE, 'create', ['image_label' => 'Ubuntu 24.04', 'region_label' => 'Falkenstein (fsn1)']);
            CloudTask::advance($retry, 'boot');
            CloudTask::fail($retry, "Hetzner Cloud didn't finish starting retry-{$code} within 10 minutes. It may still be starting: try again to keep waiting, or destroy it.");
        }
    }

    private function destroying(CloudProvider $account, string $name, string $instance, string $ip): void
    {
        $server = Server::factory()->fromProvider($account)->create([
            'name' => $name,
            'environment' => 'Production',
            'ip_address' => $ip,
            'status' => ServerStatus::Active,
            'provider_server_id' => $instance,
            'provider_monthly_cost' => '3.79',
            'provider_currency' => 'EUR',
            'meta' => ['cpu_cores' => 2, 'memory_mb' => 4096, 'disk_gb' => 40],
            'host_key_fingerprint' => null,
        ]);

        CloudTask::start($server, CloudTask::DESTROY, 'delete', ['requested' => true, 'instance' => $instance]);
        ProvisioningLog::append($server, "destroying {$name} at Hetzner Cloud");
    }

    private function cloudServer(CloudProvider $account, string $name, ?string $instance, ?string $ip = null): Server
    {
        return Server::factory()->fromProvider($account)->creating()->create([
            'name' => $name,
            'environment' => 'Production',
            'ip_address' => $ip,
            'provider_server_id' => $instance,
            'provider_monthly_cost' => '3.79',
            'provider_currency' => 'EUR',
            'host_key_fingerprint' => null,
        ]);
    }

    private function invitations(User $owner): void
    {
        TeamInvitation::factory()->for($this->team)->withToken(self::WAITING_INVITATION_TOKEN)->create(['email' => 'waiting@coresmith.test', 'invited_by' => $owner->id]);
        TeamInvitation::factory()->for($this->team)->withToken(self::EXPIRED_INVITATION_TOKEN)->expired()->create(['email' => 'expired@coresmith.test', 'invited_by' => $owner->id]);
    }

    private function databasePasswordFixtures(): void
    {
        $vault = $this->server('vault-01', '127.0.0.1', ServerStatus::Active);
        $vault->forceFill(['ssh_port' => 1])->save();

        ManagedDatabase::factory()->for($this->team)->for($vault)->create(['name' => 'vault_production', 'db_user' => 'vault_app']);
        ManagedDatabase::factory()->redis()->for($this->team)->for($vault)->create(['name' => 'vault_cache', 'db_user' => null]);

        $unused = $this->server('vault-02', '127.0.0.1', ServerStatus::Active);
        $unused->forceFill(['ssh_port' => 1, 'meta' => [...(array) $unused->meta, 'installed_databases' => ['redis']]])->save();
    }

    private function serverWithNoFreePorts(): void
    {
        $full = $this->server('full-01', '127.0.0.1', ServerStatus::Active);
        $full->forceFill(['ssh_port' => 1])->save();

        $spent = $this->application('spent-0', PortAllocator::RANGE_START, ['deleted_at' => now()->subDay()], $full);

        foreach (range(PortAllocator::RANGE_START + PortAllocator::BLOCK_SIZE, PortAllocator::RANGE_END, PortAllocator::BLOCK_SIZE) as $index => $port) {
            $name = 'spent-' . ($index + 1);

            $spent->replicate()->forceFill(['name' => $name, 'slug' => $name, 'system_user' => 'coresmith-app-' . $name, 'domain' => $name . '.bench.monoven.io', 'base_port' => $port])->saveQuietly();
        }
    }

    private function connectFixtures(): void
    {
        foreach (array_values(self::PROJECTS) as $code) {
            $this->server("connect-{$code}", '127.0.0.1', ServerStatus::Pending)->forceFill(['ssh_port' => 1])->save();
        }

        $this->server('refused-01', '203.0.113.12', ServerStatus::Pending)->forceFill([
            'ssh_user' => 'deploy',
            'last_connection_error' => 'The server refused the password for deploy. Check it and try again.',
        ])->save();

        $this->server('coresmith-box', '127.0.0.1', ServerStatus::Pending)->forceFill([
            'runs_coresmith' => true,
            'last_connection_error' => "sshd doesn't listen on 127.0.0.1, so Coresmith can't reach it from this machine and the old settings are back. Check ListenAddress in /etc/ssh/sshd_config.",
        ])->save();
    }

    private function auditFixtures(): void
    {
        $blocked = [
            ['key' => 'port_80', 'severity' => 'blocker', 'title' => 'Port 80 is in use by nginx', 'detail' => 'Coresmith needs port 80 to serve your apps over HTTPS. Stop and disable nginx, or let Coresmith do it.', 'fix' => 'stop-nginx'],
            ['key' => 'app_port_8080', 'severity' => 'warning', 'title' => 'Port 8080 is in use by node', 'detail' => 'It is inside the range Coresmith gives apps (8000–9999). Apps get other ports, or stop the process to free it.', 'fix' => null],
            ['key' => 'mysql', 'severity' => 'info', 'title' => 'MySQL is already running', 'detail' => 'Coresmith creates app databases on it and never reconfigures, restarts or removes it.', 'fix' => null],
            ['key' => 'apparmor', 'severity' => 'info', 'title' => 'AppArmor is on', 'detail' => 'Coresmith works alongside it, nothing to change.', 'fix' => null],
        ];
        $ready = [
            ['key' => 'app_users', 'severity' => 'warning', 'title' => '1 app account from an earlier Coresmith setup', 'detail' => 'Coresmith may have set this server up before. Provisioning reuses it.', 'fix' => null],
            ['key' => 'ports_free', 'severity' => 'info', 'title' => 'Ports 80 and 443 are free', 'detail' => 'Coresmith takes them when provisioning runs.', 'fix' => null],
        ];
        $done = fn(array $findings): array => ['state' => 'done', 'ran_at' => now()->addHours(5)->toIso8601String(), 'findings' => $findings];

        $connected = now()->subMinutes(5);

        $this->server('audit-server', '203.0.113.60', ServerStatus::Pending)->forceFill(['audit' => $done($blocked), 'last_connected_at' => $connected])->save();
        $this->server('checking-server', '203.0.113.61', ServerStatus::Pending)->forceFill(['audit' => ['state' => 'running', 'started_at' => now()->addHours(5)->toIso8601String()], 'last_connected_at' => $connected])->save();
        $panelHost = $this->server('panel-host', '203.0.113.63', ServerStatus::Pending);
        $panelHost->forceFill(['audit' => $done(app(PreProvisioningAudit::class)->report("== users\ncoresmith\n== panel\n/opt/coresmith\n/etc/systemd/system/coresmith-horizon.service\n", $panelHost)->toArray()), 'last_connected_at' => $connected])->save();
        $ownHost = $this->server('own-host', '127.0.0.2', ServerStatus::Pending);
        $ownHost->forceFill(['audit' => $done(app(PreProvisioningAudit::class)->report("== users\ncoresmith\n== panel\n/opt/coresmith\n", $ownHost)->toArray()), 'last_connected_at' => $connected])->save();

        foreach (array_values(self::PROJECTS) as $index => $code) {
            $this->server("blocked-{$code}", '203.0.113.' . (70 + $index), ServerStatus::Pending)->forceFill(['audit' => $done($blocked), 'last_connected_at' => $connected])->save();
            $this->server("ready-{$code}", '203.0.113.' . (80 + $index), ServerStatus::Pending)->forceFill(['audit' => $done($ready), 'last_connected_at' => $connected])->save();
        }

        $failed = fn(Server $server) => $server->forceFill([
            'last_connected_at' => $connected,
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

    private function answered500(): void
    {
        $application = $this->application('answered-500', 9900);
        $this->succeeded($application, 2 * 60, 'v2.0.0');
        $application->deployments()->latest('id')->firstOrFail()->forceFill(['live_status' => 500, 'live_detail' => 'No application encryption key has been specified.'])->save();
    }

    private function repositoryChecks(): void
    {
        foreach (array_values(self::PROJECTS) as $index => $code) {
            $this->application("unchecked-{$code}", 9700 + $index * 10, [
                'repository_url' => "git@gitlab.com:acme/unchecked-{$code}.git",
                'status' => ApplicationStatus::Pending,
                'current_release' => null,
                'repository_check_pending' => true,
            ]);
            $this->application("proposal-{$code}", 9800 + $index * 10, [
                'web_directory' => '/',
                'status' => ApplicationStatus::Pending,
                'current_release' => null,
                'repository_check_pending' => true,
                'repository_check' => [
                    'state' => 'proposal',
                    'commit' => '9f3c2e1a4b5c6d7e8f9012345678901234567890',
                    'blockers' => [
                        ['field' => 'framework', 'reason' => "There is no artisan file at the repository's root, so this isn't a Laravel app.", 'fix' => 'Pick Plain PHP, or Symfony if it uses Symfony.'],
                        ['field' => 'web_directory', 'reason' => 'There is no index.php, so the web root / would serve nothing.', 'fix' => 'Set the web root to /public, where index.php is.'],
                    ],
                    'proposal' => [
                        'verdict' => 'ready',
                        'fields' => ['type' => 'php', 'framework' => '', 'js_runtime' => null, 'web_directory' => '/public', 'version' => '8.5.10'],
                        'sources' => [],
                        'unsettled' => [],
                        'blockers' => [],
                        'label' => 'Plain PHP, PHP 8.5.10',
                    ],
                ],
            ]);
        }
    }

    private function githubFixtures(Application $atlas): void
    {
        $this->repositoryCatalog();

        $removed = $this->removedConnection();

        $this->linkedApplication('linked-app', 8070);
        $this->linkedApplication('pushing-app', 8080, ['quick_deploy' => true, 'polled_at' => now()->subSeconds(30)]);
        $this->linkedApplication('orphaned-app', 8090, ['source_provider_id' => $removed->id]);
        $this->logAt($atlas, 'application_relinked', ['from' => $removed->id, 'to' => $this->github->id, 'repository' => 'monoventech/atlas'], CarbonImmutable::now()->subHours(6));

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

    private function connectionWithoutItsApp(User $owner): void
    {
        $stray = Team::factory()->create(['name' => 'Stray Works', 'slug' => 'stray', 'owner_id' => $owner->id]);
        $stray->members()->attach($owner, ['role' => TeamRole::Owner->value]);

        foreach (array_values(self::PROJECTS) as $code) {
            CloudProvider::factory()->for($stray)->create(['name' => "Sandbox {$code}", 'api_token' => "hetzner-stray-{$code}-never-sent"]);
        }

        Server::factory()->for($stray)->create([
            'name' => 'stray-01',
            'environment' => 'Production',
            'region' => 'lon1',
            'ip_address' => '198.51.100.92',
            'status' => ServerStatus::Active,
            'os_name' => 'ubuntu',
            'os_version' => '24.04',
            'provisioning_phase' => 8,
            'last_connected_at' => now(),
        ]);

        $connection = SourceProvider::factory()->for($stray)->create([
            'type' => SourceProviderType::GitHubApp->value,
            'github_app_id' => strtolower((string) Str::ulid()),
            'name' => 'stray-works',
            'account_type' => 'Organization',
            'repository_selection' => 'selected',
            'installation_id' => '51846',
            'access_token' => null,
            'token_expires_at' => null,
        ]);
        $expires = now()->addYear();

        Cache::put(RepositoryCatalog::key($connection), ['total' => 1, 'truncated' => false, 'repositories' => [
            ['id' => '70000201', 'full_name' => 'stray-works/harbor', 'private' => true, 'default_branch' => 'main', 'archived' => false, 'pushed_at' => now()->subDay()->toIso8601ZuluString()],
        ]], $expires);
        Cache::put(RepositoryCatalog::branchesKey($connection, '70000201'), ['main'], $expires);
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

        $foundry = Team::factory()->create(['name' => 'Foundry', 'slug' => 'foundry', 'owner_id' => $owner->id]);
        $foundry->members()->attach($owner, ['role' => TeamRole::Owner->value]);
        SourceProvider::factory()->for($foundry)->create(['name' => 'foundry-works', 'access_token' => 'ghs_foundry_never_sent']);

        $kiln = Team::factory()->create(['name' => 'Kiln', 'slug' => 'kiln', 'owner_id' => $owner->id]);
        $kiln->members()->attach($owner, ['role' => TeamRole::Owner->value]);
        SourceProvider::factory()->for($kiln)->create(['name' => 'kiln-works', 'access_token' => 'ghs_kiln_never_sent']);
        $kilnCloud = CloudProvider::factory()->for($kiln)->create(['name' => 'Hetzner Cloud', 'api_token' => 'kiln-e2e-never-sent']);
        $kiln01 = Server::factory()->fromProvider($kilnCloud)->creating()->create(['name' => 'kiln-01', 'environment' => 'Production', 'host_key_fingerprint' => null]);
        CloudTask::start($kiln01, CloudTask::CREATE, 'create', ['image_label' => 'Ubuntu 24.04', 'region_label' => 'Falkenstein (fsn1)']);
        CloudTask::advance($kiln01, 'ssh');

        foreach (['Quench' => null, 'Temper' => now()->subMinutes(5)] as $name => $connectedAt) {
            $slug = strtolower($name);
            $team = Team::factory()->create(['name' => $name, 'slug' => $slug, 'owner_id' => $owner->id]);
            $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
            SourceProvider::factory()->for($team)->create(['name' => "{$slug}-works", 'access_token' => "ghs_{$slug}_never_sent"]);
            Server::factory()->for($team)->create(['name' => "{$slug}-01", 'status' => ServerStatus::Pending, 'host_key_fingerprint' => null, 'last_connected_at' => $connectedAt]);
        }
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

    public static function moverEmail(string $code): string
    {
        return "mover-{$code}@coresmith.test";
    }

    private function movers(): void
    {
        $codes = app(MoveCodes::class);
        $password = Hash::make(self::PASSWORD);

        foreach (array_values(self::PROJECTS) as $index => $code) {
            $mover = User::factory()->create(['name' => 'Jordan Mover', 'email' => self::moverEmail($code), 'password' => $password]);
            $landed = Team::factory()->create(['name' => "Landed {$code}", 'slug' => "landed-{$code}", 'owner_id' => $mover->getKey()]);
            $landed->members()->attach($mover, ['role' => TeamRole::Owner->value]);
            $mover->forceFill(['current_team_id' => $landed->getKey()])->save();

            $ip = '192.0.2.' . (220 + $index);
            $cloud = (new KeyPairGenerator)->generate('coresmith-cloud');
            Server::factory()->for($landed)->create([
                'name' => "landed-{$code}-01",
                'ip_address' => $ip,
                'ssh_private_key' => $cloud->privateKey,
                'ssh_public_key' => $cloud->publicKey,
                'status' => ServerStatus::Moving,
                'os_name' => 'ubuntu',
                'os_version' => '24.04',
                'provisioning_phase' => 8,
                'host_key_fingerprint' => 'SHA256:' . substr(base64_encode(hash('sha256', "landed-{$code}", true)), 0, 43),
                'takeover' => ['state' => 'failed', 'error' => "Coresmith couldn't reach landed-{$code}-01 at {$ip}:22. Check it's on and reachable, then try again.", 'attempts' => 1, 'cloud_key' => $cloud->publicKey],
            ]);

            $move = $codes->create($mover);
            $move->forceFill(['file' => app(MoveFile::class)->seal($this->arrivals($code, $index), $codes->read($codes->text($move)))])->save();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function arrivals(string $code, int $index): array
    {
        $key = (new KeyPairGenerator)->generate('coresmith-cloud');

        return [
            'id' => (string) Str::uuid(),
            'made_at' => now()->toIso8601String(),
            'from' => 'https://app.coresmith.io',
            'team' => ['name' => "Arrivals {$code}", 'slug' => "arrivals-{$code}"],
            'members' => [['email' => "teammate-{$code}@coresmith.test", 'role' => TeamRole::Member->value]],
            'cloud_providers' => [],
            'servers' => [[
                'ref' => 's1', 'cloud_provider' => null, 'name' => "arrivals-{$code}-01", 'ip_address' => '192.0.2.' . (200 + $index), 'ssh_port' => 22, 'ssh_user' => 'root',
                'ssh_private_key' => $key->privateKey, 'ssh_public_key' => $key->publicKey, 'host_key_fingerprint' => 'SHA256:' . substr(base64_encode(hash('sha256', "arrivals-{$code}", true)), 0, 43),
                'os_name' => 'ubuntu', 'os_version' => '24.04', 'provisioning_phase' => 8, 'installed_runtimes' => [], 'meta' => ['cpu_cores' => 2, 'memory_mb' => 4096, 'disk_gb' => 80],
                'audit_logging_enabled' => false, 'environment' => 'Production', 'region' => 'fsn1',
            ]],
            'source_providers' => [['ref' => 'gh', 'type' => SourceProviderType::GitHubApp->value, 'name' => 'Arrivals on GitHub', 'custom_host' => null]],
            'applications' => [[
                'ref' => 'a1', 'server' => 's1', 'source_provider' => 'gh', 'name' => 'storefront', 'slug' => "arrivals-{$code}-storefront", 'type' => ApplicationType::Php->value,
                'framework' => 'laravel', 'repository_url' => 'https://github.com/arrivals/storefront', 'repository_id' => '9001', 'system_user' => "coresmith-app-arr{$code}",
                'base_port' => 8100, 'php_version' => '8.5.5', 'status' => ApplicationStatus::Active->value,
            ]],
            'webhook_endpoints' => [],
            'databases' => [[
                'server' => 's1', 'type' => 'mysql', 'name' => 'storefront', 'db_user' => 'storefront', 'db_password' => Str::random(24), 'host' => '127.0.0.1', 'port' => 3306,
                'status' => 'active', 'privilege_level' => 'full', 'extensions' => [], 'is_external' => false,
            ]],
            'backup_destination' => null,
        ];
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

    private function unreachable(string $name, string $ip): void
    {
        $server = $this->server($name, $ip, ServerStatus::Active);

        $server->forceFill([
            'unreachable_since' => now()->subMinutes(25),
            'connection_failures' => 25,
            'last_connection_error' => ServerReachability::reason($server, new UnableToConnectException('Cannot connect to ' . $ip . ':22. Error 111. Connection refused')),
            'last_connected_at' => now()->subMinutes(26),
        ])->save();
    }

    private function shortOfMemory(string $name, string $ip): void
    {
        $server = $this->server($name, $ip, ServerStatus::Active);
        $newest = CarbonImmutable::now()->startOfMinute()->addMinutes(self::METRICS_AHEAD_MINUTES);

        foreach ([97, 97, 98] as $step => $memory) {
            ServerMetric::factory()->for($server)->create([
                'memory_percent' => $memory,
                'disk_percent' => 40,
                'recorded_at' => $newest->subMinutes(2 - $step),
            ]);
        }
    }

    private function rekeyed(string $name, string $ip): void
    {
        $this->server($name, $ip, ServerStatus::Active)->forceFill([
            'host_key_fingerprint' => self::ORIGINAL_HOST_KEY,
            'pending_host_key_fingerprint' => self::PRESENTED_HOST_KEY,
            'host_key_changed_at' => now()->subMinutes(9),
        ])->save();
    }

    private function cronFixtures(): void
    {
        $scheduler = ['schedule' => '* * * * *', 'command' => 'php artisan schedule:run'];
        $unreachable = "Couldn't reach bench-01 over SSH. Check it's online, then try again.";

        $cronJobs = $this->application('cron-jobs', 8500, ['cron_jobs' => [
            $scheduler,
            ['schedule' => '0 3 * * *', 'command' => 'php artisan backup:run', 'description' => 'Nightly backup'],
            ['schedule' => '30 8 * * 1', 'command' => 'php artisan report:weekly'],
            ['schedule' => '15 9-17 * * 1-5', 'command' => "php artisan prices:sync\nphp artisan cache:clear --tags=prices", 'description' => 'Price sync'],
        ]]);
        $this->succeeded($cronJobs, 4 * 60, 'v1.2.0');
        $this->schedulerLog($cronJobs, [
            '[2026-11-06 08:11:00] Running [schedule:run] first line',
            ...array_map(fn(int $minute): string => sprintf('[2026-11-06 08:%02d:00] Running [inspire] .... 4ms DONE', $minute + 12), range(0, 44)),
            "[2026-11-06 08:57:00] Running [report:weekly]\tqueued",
            '[2026-11-06 08:58:00] ' . str_repeat('prices:sync updated a long list of products without pausing ', 7),
            '[2026-11-06 08:59:00] Running [backup:run] .... 1s DONE',
            '[2026-11-06 09:00:00] Running [prune] .... DONE',
        ]);

        $this->succeeded($this->application('cron-failed', 8510, ['cron_jobs' => [$scheduler], 'cron_error' => $unreachable]), 4 * 60, 'v1.0.0');
        $cronPaused = $this->application('cron-paused', 8520, ['cron_jobs' => [$scheduler], 'power_state' => PowerState::Stopped]);
        $this->succeeded($cronPaused, 26 * 60, 'v1.0.0');
        Cache::put(ReadSchedulerLog::cacheKey($cronPaused), ['lines' => [], 'error' => "Couldn't read the scheduler log on bench-01.", 'read_at' => now()->subDay()->timestamp], now()->addYear());
        $this->succeeded($this->application('cron-full', 8550, ['cron_jobs' => array_map(fn(int $minute): array => ['schedule' => "{$minute} * * * *", 'command' => "php artisan report:{$minute}"], range(0, CronJobs::MAX_ENTRIES - 1))]), 4 * 60, 'v1.0.0');
        $this->application('cron-new', 8530, ['cron_jobs' => [$scheduler]]);
        $this->succeeded($this->application('cron-waiting', 8540, ['cron_jobs' => [$scheduler], 'cron_pending_since' => now()->subMinutes(10)]), 4 * 60, 'v1.0.0');

        foreach (array_values(self::PROJECTS) as $index => $code) {
            $this->succeeded($this->application("cron-{$code}", 8600 + $index * 30), 3 * 60, 'v1.0.0');
            $this->succeeded($this->application("cron-toggle-{$code}", 8610 + $index * 30, ['cron_jobs' => [$scheduler]]), 3 * 60, 'v1.0.0');
            $this->succeeded($this->application("cron-failed-{$code}", 8620 + $index * 30, ['cron_jobs' => [$scheduler], 'cron_error' => $unreachable]), 3 * 60, 'v1.0.0');
        }
    }

    /**
     * @param  list<string>  $lines
     */
    private function schedulerLog(Application $application, array $lines): void
    {
        $lines = array_map(fn(string $line): string => mb_substr($line, 0, SchedulerLogReader::LINE_LENGTH), $lines);

        Cache::put(ReadSchedulerLog::cacheKey($application), ['lines' => array_slice($lines, -SchedulerLogReader::LINES), 'error' => null, 'read_at' => now()->subDay()->timestamp], now()->addYear());
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function domainReach(Application $atlas): void
    {
        $domain = (string) $atlas->domain;

        $atlas->forceFill(['domain_reach' => [
            $domain => [
                'ready' => false,
                'problems' => ["{$domain} points at 203.0.113.7, but nothing reaches this server on ports 80 and 443 from the internet. Either your router isn't forwarding those ports to 192.168.0.248, or your internet provider shares that address with other customers. The surest fix is IPv6 with Cloudflare's proxy: add an AAAA record for {$domain} pointing at 2001:db8::7, remove the A record, and turn the proxy on, so visitors without IPv6 reach it too. That needs {$domain}'s DNS on Cloudflare. If your router blocks incoming IPv6, allow ports 80 and 443 to 2001:db8::7 in its IPv6 firewall. Keep the A record instead only if 203.0.113.7 is yours and you can forward ports 80 and 443 to 192.168.0.248, then check again."],
                'notes' => [],
                'records' => [['type' => 'AAAA', 'name' => $domain, 'value' => '2001:db8::7', 'note' => "Best fix, with Cloudflare's proxy on"]],
                'checked_at' => now()->subMinutes(20)->toIso8601String(),
            ],
        ]])->save();

        $atlas->server->forceFill(['public_addresses' => [
            'ipv4' => '203.0.113.7',
            'ipv4_open' => false,
            'ipv6' => ['2001:db8::7'],
            'checked_at' => now()->subMinutes(20)->toIso8601String(),
        ]])->save();
    }

    private function application(string $name, int $port, array $attributes = [], ?Server $server = null): Application
    {
        return Application::factory()->for($this->team)->for($server ?? $this->bench)->create([
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

    private function certificatesChecked(Application $application): void
    {
        $this->succeeded($application, 2 * 60, 'v1.0.0');

        $now = CarbonImmutable::now();
        $checked = $now->toIso8601String();

        Cache::put(CertificateCheck::cacheKey($application), ['domains' => [
            'certs.monoven.io' => ['expires_at' => $now->addDays(60)->toIso8601String(), 'checked_at' => $checked],
            'soon.monoven.io' => ['expires_at' => $now->addDays(3)->addHours(12)->toIso8601String(), 'checked_at' => $checked],
            'gone.monoven.io' => ['expires_at' => $now->subDays(2)->toIso8601String(), 'checked_at' => $checked],
            'dark.monoven.io' => ['expires_at' => null, 'checked_at' => $checked],
        ]], $now->addYear());
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function realtime(string $name, int $port, array $attributes = [], ?Server $server = null): Application
    {
        $application = $this->application($name, $port, [
            ...MonovenTenant::stack(
                ['php' => ['8.5.10', 'composer.json', '^8.4']],
                ['name' => 'laravel', 'major' => 13],
                new JsTooling('bun', true, true, false, ssr: true),
                packages: ['horizon' => true, 'reverb' => true, 'inertia' => true],
            ),
            'domain' => $name . '.monoven.io',
            ...$attributes,
        ], $server);

        $this->succeeded($application, 6 * 60, 'v1.0.0');

        return $application;
    }

    private function releaseMovedOn(Application $application): void
    {
        $application->forceFill([
            'toggles' => ['reverb' => true, SmartToggles::INERTIA_SSR => true],
            'ssr_port' => 13790,
            'stack' => [...(array) $application->stack, 'tooling' => [...(array) ((array) $application->stack)['tooling'], 'ssr' => false], 'packages' => ['horizon' => true, 'reverb' => false, 'inertia' => true]],
        ])->save();
    }

    private function ssrDidNotStart(Application $application): void
    {
        $application->forceFill(['toggles' => [SmartToggles::INERTIA_SSR => true], 'ssr_port' => 13791])->save();

        activity()
            ->performedOn($application)
            ->withProperties(['toggle' => SmartToggles::INERTIA_SSR, 'label' => 'Inertia SSR', 'error' => 'The SSR server did not start on port 13791. Pass Number(process.env.INERTIA_SSR_PORT) as the port in your SSR entry, then deploy again.'])
            ->log(SmartToggles::START_FAILED);
    }

    private function heldByAQueuedDeploy(Application $application): void
    {
        $this->succeeded($application, 3 * 24 * 60, 'v1.0.0');
        $this->succeeded($application, 2 * 60, 'v1.1.0');
        $this->queued($application);
    }

    private function queued(Application $application): void
    {
        Deployment::factory()->for($application)->create([
            'branch' => 'main',
            'commit_hash' => null,
            'commit_message' => null,
            'status' => DeploymentStatus::Pending,
            'created_at' => CarbonImmutable::now()->subMinutes(5),
        ]);
    }

    private function cancelFixtures(User $owner): void
    {
        $cancel = $this->server('cancel-01', '198.51.100.72', ServerStatus::Active);

        foreach (array_values(self::PROJECTS) as $index => $code) {
            $this->runningForTwoHours($this->application("cancel-{$code}", 9000 + $index * 10, server: $cancel));
            $this->runningForTwoHours($this->application("streamed-{$code}", 9230 + $index * 10, server: $cancel));
            $this->queued($this->application("queued-{$code}", 9050 + $index * 10));
        }

        $this->runningForTwoHours($this->application('migrating-app', 9100, server: $cancel), ['build: building the release', 'migrate: running migrations against release {release}']);
        $this->runningForTwoHours($this->application('stopping-app', 9120, server: $cancel), [], $owner);
        $this->cancelledBy($this->application('cancelled-app', 9110), $owner);
    }

    /**
     * @param  list<string>  $after
     */
    private function runningForTwoHours(Application $application, array $after = [], ?User $stopping = null): void
    {
        $started = CarbonImmutable::now()->subHours(2);
        $release = $started->format('Ymd-His');
        $markers = ['clone: fetching over https', 'deps: installing dependencies', ...$after];
        $log = ['[' . $started->format('H:i:s') . '] release ' . $release . ' · branch main · triggered by Maya Lindqvist'];

        foreach ($markers as $step => $marker) {
            $log[] = '[' . $started->addSeconds(1 + $step * 20)->format('H:i:s') . '] ' . str_replace('{release}', $release, $marker);
        }

        if ($stopping !== null) {
            $log[] = "cancel: {$stopping->name} asked to stop the deploy";
        }

        Deployment::factory()->for($application)->create([
            'release' => $release,
            'branch' => 'main',
            'commit_hash' => substr(hash('sha1', $application->name), 0, 40),
            'commit_message' => 'Ship ' . $application->name,
            'commit_author' => 'Maya Lindqvist',
            'status' => DeploymentStatus::Running,
            'started_at' => $started,
            'created_at' => $started,
            'stale_after' => CarbonImmutable::now()->addHours(6),
            'cancel_requested_at' => $stopping === null ? null : $started->addMinutes(3),
            'cancelled_by' => $stopping?->id,
            'log' => implode("\n", $log),
        ]);

        $application->forceFill(['status' => ApplicationStatus::Deploying])->save();
    }

    private function cancelledBy(Application $application, User $owner): void
    {
        $this->succeeded($application, 3 * 60, 'v2.0.0');

        $started = CarbonImmutable::now()->subMinutes(10);
        $release = $started->format('Ymd-His');
        $at = fn(int $seconds): string => '[' . $started->addSeconds($seconds)->format('H:i:s') . ']';

        Deployment::factory()->for($application)->create([
            'release' => $release,
            'branch' => 'main',
            'commit_hash' => substr(hash('sha1', $application->name . 'cancelled'), 0, 40),
            'commit_message' => 'Try the new pricing page',
            'commit_author' => 'Maya Lindqvist',
            'status' => DeploymentStatus::Cancelled,
            'started_at' => $started,
            'finished_at' => $started->addSeconds(41),
            'created_at' => $started,
            'cancel_requested_at' => $started->addSeconds(35),
            'cancelled_by' => $owner->id,
            'log' => implode("\n", [
                $at(0) . ' release ' . $release . ' · branch main · triggered by Maya Lindqvist',
                $at(1) . ' clone: fetching over https',
                $at(4) . ' deps: installing dependencies',
                $at(30) . ' build: building the release',
                "cancel: {$owner->name} asked to stop the deploy",
                $at(41) . ' deploy cancelled. the current release is untouched.',
            ]),
        ]);
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

    private function python(string $name, int $port): Application
    {
        $attributes = MonovenTenant::stack(['python' => ['3.14.10', '.python-version', '3.14']]);
        $attributes['stack']['language_tooling'] = ['manager' => 'uv', 'server' => 'gunicorn', 'module' => 'tasks.wsgi:application', 'collectstatic' => true, 'migrate' => 'django', 'celery' => 'tasks'];

        return $this->application($name, $port, [
            'type' => ApplicationType::Python,
            'framework' => 'django',
            'php_version' => null,
            ...$attributes,
            'env_variables' => 'SECRET_KEY=' . bin2hex(random_bytes(32)) . "\nALLOWED_HOSTS={$name}.bench.monoven.io",
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
            'error' => (new ProcessDidNotStart("coresmith-app-{$application->slug}-web", 'activating', '', $advice))->getMessage(),
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
            'error' => (new ProcessDidNotStart("coresmith-app-{$application->slug}-sidekiq", 'activating', '', 'The process log says why. Fix it and deploy again, or roll back to the previous release.'))->getMessage(),
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
        $reason = (new ProcessDidNotStart($unit, 'activating', '', $advice))->getMessage();

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

        $error = (new ProcessDidNotStart("coresmith-app-{$application->slug}-jobs", 'activating', '', 'The entry point was not found. Check the build produced the file the process starts, then deploy again.'))->getMessage();

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
