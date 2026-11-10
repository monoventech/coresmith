<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace Database\Seeders\Support;

use App\Enums\ApplicationStatus;
use App\Enums\ApplicationType;
use App\Enums\DeploymentStatus;
use App\Enums\ServerStatus;
use App\Enums\SourceProviderType;
use App\Enums\TeamRole;
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
use App\Models\User;
use App\Services\Cloud\CloudCatalog;
use App\Services\Git\DeployKeyManager;
use App\Services\Runtime\JsTooling;
use App\Services\Runtime\ResolvedRuntime;
use App\Services\Runtime\Stack;
use App\Services\SSH\HostKeyFingerprint;
use App\Services\SSH\KeyPairGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Hash;
use Spatie\Activitylog\Models\Activity;

final class MonovenTenant
{
    /** @var array<string, Server> */
    public array $servers = [];

    /** @var array<string, Application> */
    public array $applications = [];

    public Team $team;

    public User $owner;

    public User $member;

    public SourceProvider $github;

    public CloudProvider $cloud;

    public function __construct(
        private readonly int $metricsAhead = 0,
        private readonly int $tokenMinutes = 41,
    ) {}

    /**
     * @param  array{owner: string, admin: string, member: string, password: string}  $credentials
     */
    public function build(array $credentials): self
    {
        $password = Hash::make($credentials['password']);

        $this->owner = User::factory()->create(['name' => 'Maya Lindqvist', 'email' => $credentials['owner'], 'password' => $password]);
        $admin = User::factory()->withTwoFactor()->create(['name' => 'Jonas Weber', 'email' => $credentials['admin'], 'password' => $password]);
        $this->member = User::factory()->create(['name' => 'Priya Nair', 'email' => $credentials['member'], 'password' => $password]);

        $this->team = Team::factory()->create(['name' => 'Monoven Ltd', 'slug' => 'monoven', 'owner_id' => $this->owner->id]);
        $this->team->members()->attach($this->owner, ['role' => TeamRole::Owner->value]);
        $this->team->members()->attach($admin, ['role' => TeamRole::Admin->value]);
        $this->team->members()->attach($this->member, ['role' => TeamRole::Member->value]);

        foreach ([$this->owner, $admin, $this->member] as $user) {
            $user->forceFill(['current_team_id' => $this->team->id])->save();
        }

        $this->sshKeys();
        $this->sourceProvider();
        $this->cloudProvider();
        $this->servers();
        $this->applications();
        $this->databases();
        $this->deployments();
        $this->activity();

        return $this;
    }

    /**
     * @param  array<string, array{0: string, 1: string, 2: ?string}>  $runtimes
     * @param  array{name: string, major: int}|null  $framework
     * @param  array<string, bool>  $gems
     * @return array<string, mixed>
     */
    public static function stack(array $runtimes, ?array $framework = null, ?JsTooling $tooling = null, array $gems = [], array $packages = []): array
    {
        $resolved = [];

        foreach ($runtimes as $runtime => [$version, $source, $detail]) {
            $resolved[$runtime] = new ResolvedRuntime($runtime, $version, $source, $detail, true);
        }

        return (new Stack($resolved, $framework, $tooling, gems: $gems, packages: $packages))->applicationAttributes();
    }

    private function sshKeys(): void
    {
        foreach (['maya@monoven' => 400, 'ci-runner' => 180] as $name => $daysAgo) {
            $public = (new KeyPairGenerator)->generate()->publicKey;

            SshKey::factory()->for($this->owner)->create([
                'name' => $name,
                'public_key' => $public,
                'fingerprint' => (string) HostKeyFingerprint::fromPublicKey($public),
                'created_at' => now()->subDays($daysAgo),
            ]);
        }
    }

    private function sourceProvider(): void
    {
        $app = GitHubApp::factory()->withoutWebhook()->for($this->team)->create([
            'app_id' => '1041207',
            'slug' => 'coresmith-monoven',
            'name' => 'Coresmith Monoven',
            'owner_login' => 'monoventech',
            'owner_type' => 'Organization',
            'html_url' => 'https://github.com/apps/coresmith-monoven',
            'created_by' => $this->owner->id,
        ]);

        $this->github = SourceProvider::factory()->for($this->team)->githubApp($app)->create([
            'type' => SourceProviderType::GitHubApp->value,
            'name' => 'monoventech',
            'account_type' => 'Organization',
            'repository_selection' => 'selected',
            'access_token' => 'ghs_demo_never_rendered',
            'installation_id' => '51842',
            'token_expires_at' => now()->addMinutes($this->tokenMinutes),
        ]);
    }

    private function cloudProvider(): void
    {
        $this->cloud = CloudProvider::factory()->digitalocean()->for($this->team)->create([
            'name' => 'DigitalOcean',
            'account_label' => 'ops@monoven.io',
            'api_token' => 'do-demo-never-sent',
            'verified_at' => now(),
        ]);

        CloudCatalog::put($this->cloud, CloudCatalogs::digitalocean(), 30 * 24 * 3600);
    }

    private function servers(): void
    {
        $specs = [
            'forge-01' => ['Production', 'fra1', '203.0.113.14', 8192, 214, [34, 14, 0], [61, 6, 2], [42, 1, 4]],
            'forge-02' => ['Staging', 'fra1', '203.0.113.87', 4096, 61, [62, 20, 1], [55, 8, 3], [39, 1, 1]],
            'anvil-01' => ['Production', 'nyc3', '198.51.100.3', 16384, 380, [12, 5, 2], [44, 4, 0], [58, 1, 3]],
            'outpost-01' => ['Production', 'sgp1', '192.0.2.9', 2048, 129, [22, 8, 1], [37, 5, 2], [84, 0.4, 0]],
        ];

        $order = 0;

        foreach ($specs as $name => [$environment, $region, $ip, $memory, $uptime, $cpu, $mem, $disk]) {
            $server = Server::factory()->for($this->team)->create([
                'name' => $name,
                'environment' => $environment,
                'region' => $region,
                'ip_address' => $ip,
                'status' => ServerStatus::Active,
                'os_name' => 'ubuntu',
                'os_version' => '24.04',
                'meta' => ['cpu_cores' => max(1, intdiv($memory, 2048)), 'memory_mb' => $memory, 'disk_gb' => 160, 'caddy_admin' => 'socket', 'ssh_hardening' => 'verified', 'caddy_unknown_hosts' => 'closed'],
                'provisioning_phase' => 8,
                'host_key_fingerprint' => 'SHA256:' . substr(base64_encode(hash('sha256', $name, true)), 0, 43),
                'last_connected_at' => now(),
                'booted_at' => now()->subDays($uptime)->subHours(3),
                'created_at' => now()->subDays(400 - $order++),
                ...($name === 'outpost-01' ? [
                    'cloud_provider_id' => $this->cloud->id,
                    'provider_server_id' => '512190',
                    'provider_size' => 's-1vcpu-2gb',
                    'provider_image' => 'ubuntu-24-04-x64',
                    'provider_monthly_cost' => '12.00',
                    'provider_currency' => 'USD',
                ] : []),
            ]);

            $this->metrics($server, $cpu, $mem, $disk);
            $this->servers[$name] = $server;
        }
    }

    /**
     * @param  array{0: int|float, 1: int|float, 2: int|float}  $cpu
     * @param  array{0: int|float, 1: int|float, 2: int|float}  $mem
     * @param  array{0: int|float, 1: int|float, 2: int|float}  $disk
     */
    private function metrics(Server $server, array $cpu, array $mem, array $disk): void
    {
        $now = CarbonImmutable::now()->startOfMinute();
        $rows = [];

        foreach (range(24 * 60, -$this->metricsAhead) as $minutesAgo) {
            $hours = (24 * 60 - $minutesAgo) / 60;

            $rows[] = [
                'server_id' => $server->id,
                'cpu_percent' => $this->wave($cpu, $hours, $minutesAgo),
                'memory_percent' => $this->wave($mem, $hours, $minutesAgo),
                'disk_percent' => $minutesAgo <= 0 ? $disk[0] : $this->wave($disk, $hours, $minutesAgo),
                'load_1m' => round($cpu[0] / 25, 2),
                'load_5m' => round($cpu[0] / 28, 2),
                'load_15m' => round($cpu[0] / 30, 2),
                'network_rx_bytes' => 1048576 * (1440 - $minutesAgo),
                'network_tx_bytes' => 524288 * (1440 - $minutesAgo),
                'recorded_at' => $now->subMinutes($minutesAgo)->toDateTimeString(),
            ];

            if (count($rows) === 500) {
                ServerMetric::insert($rows);
                $rows = [];
            }
        }

        ServerMetric::insert($rows);
    }

    /**
     * @param  array{0: int|float, 1: int|float, 2: int|float}  $shape
     */
    private function wave(array $shape, float $hours, int $minutesAgo): float
    {
        [$base, $amplitude, $phase] = $shape;

        return round(max(1, min(99, $base + $amplitude * sin($hours / 2.4 + $phase) + (($minutesAgo * 7) % 5) * 0.4)), 1);
    }

    private function applications(): void
    {
        $specs = [
            ['atlas', 'forge-01', ApplicationType::Php, 'laravel', self::stack(['php' => ['8.5.10', 'composer.json', '^8.4'], 'bun' => ['1.4.2', 'default', null]], ['name' => 'laravel', 'major' => 13], new JsTooling('bun', true, true, false)), 8000, 'atlas.monoven.io', ['www.atlas.monoven.io']],
            ['ledger', 'forge-01', ApplicationType::Ruby, 'rails', self::stack(['ruby' => ['3.4.10', '.ruby-version', '3.4.10']], ['name' => 'rails', 'major' => 8], null, ['sidekiq' => true, 'solid_queue' => false]), 8010, 'ledger.monoven.io', []],
            ['relay', 'forge-01', ApplicationType::Js, null, ['js_runtime' => 'deno', ...self::stack(['deno' => ['2.9.7', '.dvmrc', '2.9.7']], null, new JsTooling('deno', true, false, true))], 8020, 'relay.monoven.io', []],
            ['storefront', 'forge-01', ApplicationType::Js, 'sveltekit', ['js_runtime' => 'bun', ...self::stack(['bun' => ['1.4.2', 'package.json', 'bun@1.4.2']], ['name' => 'sveltekit', 'major' => 2], new JsTooling('bun', true, true, false))], 8030, 'shop.monoven.io', []],
            ['atlas-staging', 'forge-02', ApplicationType::Php, 'laravel', self::stack(['php' => ['8.5.10', 'composer.json', '^8.4'], 'bun' => ['1.4.2', 'default', null]], ['name' => 'laravel', 'major' => 13], new JsTooling('bun', true, true, false)), 8000, 'staging.atlas.monoven.io', []],
            ['storefront-staging', 'forge-02', ApplicationType::Js, 'sveltekit', ['js_runtime' => 'bun', ...self::stack(['bun' => ['1.4.2', 'package.json', 'bun@1.4.2']], ['name' => 'sveltekit', 'major' => 2], new JsTooling('bun', true, true, false))], 8010, 'staging.shop.monoven.io', []],
            ['warehouse', 'anvil-01', ApplicationType::Ruby, 'rails', self::stack(['ruby' => ['3.3.12', '.ruby-version', '3.3.12']], ['name' => 'rails', 'major' => 8], null, ['sidekiq' => false, 'solid_queue' => true]), 8000, 'warehouse.monoven.io', []],
            ['relay-edge', 'outpost-01', ApplicationType::Js, null, ['js_runtime' => 'deno', ...self::stack(['deno' => ['2.9.7', '.dvmrc', '2.9.7']], null, new JsTooling('deno', true, false, true))], 8000, 'edge.relay.monoven.io', []],
        ];

        foreach ($specs as [$name, $serverName, $type, $framework, $runtime, $port, $domain, $extra]) {
            $application = Application::factory()->for($this->team)->for($this->servers[$serverName])->create([
                'name' => $name,
                'slug' => $name,
                'system_user' => 'coresmith-app-' . $name,
                'type' => $type,
                'framework' => $framework,
                'php_version' => null,
                ...$runtime,
                'domain' => $domain,
                'additional_domains' => $extra === [] ? null : $extra,
                'repository_url' => 'git@github.com:monoventech/' . str_replace('-staging', '', $name) . '.git',
                'repository_branch' => 'main',
                'base_port' => $port,
                'status' => ApplicationStatus::Active,
                'env_variables' => $this->env($name, $type),
                'processes' => $framework === 'laravel' ? [['name' => 'queue', 'command' => 'php artisan queue:work --sleep=3 --tries=3']] : null,
                'cron_jobs' => $framework === 'laravel' ? [['schedule' => '* * * * *', 'command' => 'php artisan schedule:run']] : null,
            ]);

            app(DeployKeyManager::class)->ensure($application);

            $this->applications[$name] = $application;
        }
    }

    private function env(string $name, ApplicationType $type): string
    {
        $user = str_replace('-', '_', $name) . '_app';

        return match ($type) {
            ApplicationType::Php => "APP_ENV=production\nDB_USERNAME={$user}\nDB_PASSWORD=" . bin2hex(random_bytes(12)) . "\nREDIS_PREFIX={$name}:",
            ApplicationType::Ruby => "RAILS_ENV=production\nDATABASE_USER={$user}\nDATABASE_PASSWORD=" . bin2hex(random_bytes(12)) . "\nSECRET_KEY_BASE=" . bin2hex(random_bytes(16)),
            default => "NODE_ENV=production\nPORT_HINT=managed\nAPI_TOKEN=" . bin2hex(random_bytes(12)),
        };
    }

    private function databases(): void
    {
        ManagedDatabase::factory()->postgres()->for($this->team)->for($this->servers['forge-01'])->create(['name' => 'atlas_production', 'db_user' => 'atlas_app']);
        ManagedDatabase::factory()->postgres()->for($this->team)->for($this->servers['forge-01'])->create(['name' => 'ledger_production', 'db_user' => 'ledger_app']);
        ManagedDatabase::factory()->for($this->team)->for($this->servers['anvil-01'])->create(['name' => 'warehouse', 'db_user' => 'warehouse_app']);
        ManagedDatabase::factory()->for($this->team)->for($this->servers['forge-01'])->create(['name' => 'legacy_reports', 'db_user' => 'reports_app', 'is_external' => true]);
        ManagedDatabase::factory()->redis()->for($this->team)->for($this->servers['forge-01'])->create(['name' => 'cache_production', 'db_user' => null, 'port' => 6380]);
    }

    private function deployments(): void
    {
        $atlas = $this->applications['atlas'];

        $this->release($atlas, 10 * 24 * 60, 'main', '7d40fe2', 'Maya Lindqvist', 'v2.13.0', true, 126);
        $this->release($atlas, 7 * 24 * 60, 'main', '1c93b8a', 'Jonas Weber', 'v2.13.1', true, 118);
        $this->release($atlas, 6 * 24 * 60, 'dev', 'e27a55f', 'Priya Nair', 'v2.15.0-beta.2', false, 131);
        $this->release($atlas, 3 * 24 * 60, 'main', '4b81d0c', 'Maya Lindqvist', 'v2.13.2', true, 124);
        $this->release($atlas, 110, 'main', '9f3c2e1', 'Maya Lindqvist', 'v2.14.0', true, 113);

        $this->release($this->applications['ledger'], 135, 'main', '3a61b0e', 'Jonas Weber', 'v3.2.1', true, 132);
        $this->release($this->applications['relay'], 6 * 24 * 60 + 90, 'main', 'b7c21d9', 'Priya Nair', null, null, 58);
        $this->release($this->applications['storefront'], 5 * 24 * 60, 'main', '5e08aa4', 'Maya Lindqvist', 'v1.9.0', true, 104);
        $this->failed($this->applications['storefront'], 26 * 60, 'main', 'c47ab90', 'Priya Nair');
        $this->release($this->applications['storefront-staging'], 22 * 60, 'main', 'd19f3be', 'Priya Nair', null, null, 96);
        $this->release($this->applications['warehouse'], 2 * 24 * 60, 'main', '8e2f1c7', 'Jonas Weber', 'v4.0.3', true, 141);
        $this->release($this->applications['relay-edge'], 4 * 24 * 60, 'main', 'a91d4e2', 'Priya Nair', null, null, 61);
        $this->release($this->applications['atlas-staging'], 2 * 24 * 60, 'main', '4b81d0c', 'Maya Lindqvist', null, null, 119);
        $this->running($this->applications['atlas-staging'], 'main', '9f3c2e1', 'Maya Lindqvist');
    }

    private function release(Application $application, int $minutesAgo, string $branch, string $sha, string $author, ?string $tag, ?bool $signed, int $seconds): void
    {
        $finished = CarbonImmutable::now()->subMinutes($minutesAgo);
        $started = $finished->subSeconds($seconds);
        $release = $started->format('Ymd-His');

        Deployment::factory()->for($application)->create([
            'triggered_by' => $this->owner->id,
            'release' => $release,
            'branch' => $branch,
            'commit_hash' => $sha . substr(hash('sha1', $sha), 0, 33),
            'commit_message' => 'Ship ' . ($tag ?? 'the latest changes'),
            'commit_author' => $author,
            'tag' => $tag,
            'tag_signed' => $signed,
            'status' => DeploymentStatus::Succeeded,
            'log' => DemoLog::succeeded($application, $release, $branch, $sha, $author, $tag, $started, $seconds),
            'started_at' => $started,
            'finished_at' => $finished,
            'created_at' => $started,
        ]);

        $application->forceFill(['current_release' => $release])->save();
    }

    private function failed(Application $application, int $minutesAgo, string $branch, string $sha, string $author): void
    {
        $finished = CarbonImmutable::now()->subMinutes($minutesAgo);
        $started = $finished->subSeconds(108);
        $release = $started->format('Ymd-His');

        Deployment::factory()->for($application)->create([
            'triggered_by' => $this->owner->id,
            'release' => $release,
            'branch' => $branch,
            'commit_hash' => $sha . substr(hash('sha1', $sha), 0, 33),
            'commit_message' => 'Move pricing into its own module',
            'commit_author' => $author,
            'status' => DeploymentStatus::Failed,
            'log' => DemoLog::failed($application, $release, $branch, $sha, $author, $started),
            'started_at' => $started,
            'finished_at' => $finished,
            'created_at' => $started,
        ]);
    }

    private function running(Application $application, string $branch, string $sha, string $author): void
    {
        $started = CarbonImmutable::now()->subSeconds(84);
        $release = $started->format('Ymd-His');

        Deployment::factory()->for($application)->create([
            'triggered_by' => $this->owner->id,
            'release' => $release,
            'branch' => $branch,
            'commit_hash' => $sha . substr(hash('sha1', $sha), 0, 33),
            'commit_message' => 'Ship v2.14.0 to staging',
            'commit_author' => $author,
            'status' => DeploymentStatus::Running,
            'log' => DemoLog::running($application, $release, $branch, $sha, $author, $started),
            'started_at' => $started,
            'created_at' => $started,
        ]);

        $application->forceFill(['status' => ApplicationStatus::Deploying])->save();
    }

    private function activity(): void
    {
        $entries = [
            [$this->applications['atlas'], 'environment_updated', [], now()->subHours(5)],
            [$this->servers['forge-01'], 'firewall_rule_changed', ['action' => 'allow', 'port' => 9090, 'from' => '203.0.113.50'], now()->subDays(1)->subHours(6)],
            [$this->servers['outpost-01'], 'disk_pressure', ['percent' => 84], now()->subDay()],
            [$this->servers['outpost-01'], 'server_provisioned', ['duration_seconds' => 372], now()->subDays(131)],
        ];

        foreach ($entries as [$subject, $description, $properties, $at]) {
            $activity = activity()->performedOn($subject)->withProperties($properties)->log($description);

            if ($activity instanceof Activity) {
                $activity->forceFill(['created_at' => $at, 'updated_at' => $at])->save();
            }
        }
    }
}
