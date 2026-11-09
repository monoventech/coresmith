<?php

declare(strict_types=1);

use App\Enums\ServerStatus;
use App\Enums\SourceProviderType;
use App\Enums\TeamRole;
use App\Jobs\TakeOverServer;
use App\Models\Application;
use App\Models\GitHubApp;
use App\Models\ManagedDatabase;
use App\Models\MoveCode;
use App\Models\Server;
use App\Models\SourceProvider;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Services\Git\GitHub\ApplicationRelinker;
use App\Services\Moves\MoveCodes;
use App\Services\Moves\TeamImporter;
use App\Services\SSH\KeyPairGenerator;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

function teamImporterPayload(array $overrides = []): array
{
    $web = (new KeyPairGenerator)->generate('cloud-web');
    $db = (new KeyPairGenerator)->generate('cloud-db');

    return array_replace([
        'id' => 'b2b0c0de-0000-4000-8000-000000000001',
        'made_at' => '2026-11-08T12:00:00+00:00',
        'from' => 'https://app.coresmith.io',
        'team' => ['name' => 'Acme', 'slug' => 'acme'],
        'members' => [['email' => 'ada@acme.test', 'role' => 'admin'], ['email' => 'lin@acme.test', 'role' => 'member']],
        'cloud_providers' => [['ref' => 'cp1', 'type' => 'hetzner', 'name' => 'Hetzner', 'account_label' => 'acme', 'api_token' => 'hetzner-token-moves', 'verified_at' => '2026-11-01T10:00:00+00:00']],
        'servers' => [
            ['ref' => 's1', 'cloud_provider' => 'cp1', 'name' => 'web-01', 'ip_address' => '203.0.113.10', 'ssh_port' => 22, 'ssh_user' => 'root', 'ssh_private_key' => $web->privateKey, 'ssh_public_key' => $web->publicKey, 'host_key_fingerprint' => 'SHA256:webkey', 'os_name' => 'ubuntu', 'os_version' => '24.04', 'provisioning_phase' => 8, 'installed_runtimes' => ['php' => ['8.5.5']], 'meta' => ['cpu_cores' => 2], 'audit_logging_enabled' => true, 'environment' => 'Production', 'region' => 'fsn1', 'booted_at' => null, 'provider_server_id' => '4711', 'provider_size' => 'cx22', 'provider_image' => 'ubuntu-24.04', 'provider_monthly_cost' => '4.35', 'provider_currency' => 'EUR'],
            ['ref' => 's2', 'cloud_provider' => null, 'name' => 'db-01', 'ip_address' => '203.0.113.11', 'ssh_port' => 2222, 'ssh_user' => 'root', 'ssh_private_key' => $db->privateKey, 'ssh_public_key' => $db->publicKey, 'host_key_fingerprint' => 'SHA256:dbkey', 'os_name' => 'ubuntu', 'os_version' => '24.04', 'provisioning_phase' => 8, 'installed_runtimes' => [], 'meta' => null, 'audit_logging_enabled' => false, 'environment' => null, 'region' => null, 'booted_at' => null, 'provider_server_id' => null, 'provider_size' => null, 'provider_image' => null, 'provider_monthly_cost' => null, 'provider_currency' => null],
        ],
        'source_providers' => [
            ['ref' => 'gh', 'type' => 'github_app', 'name' => 'Acme on GitHub', 'custom_host' => null],
            ['ref' => 'gl', 'type' => 'gitlab', 'name' => 'Acme on GitLab', 'custom_host' => null],
            ['ref' => 'cu', 'type' => 'custom', 'name' => 'Our Gitea', 'custom_host' => null],
        ],
        'applications' => [
            ['ref' => 'a1', 'server' => 's1', 'source_provider' => 'gh', 'name' => 'site', 'slug' => 'acme-site', 'type' => 'php', 'framework' => 'laravel', 'repository_url' => 'https://github.com/acme/site', 'repository_id' => '123456', 'env_variables' => "APP_KEY=base64:secret-site\nDB_PASSWORD=site-db-password", 'system_user' => 'coresmith-app-site', 'base_port' => 8100, 'php_version' => '8.5.5', 'processes' => [['name' => 'queue', 'command' => 'php artisan queue:work']], 'cron_jobs' => [['command' => 'php artisan schedule:run', 'schedule' => '* * * * *']], 'additional_domains' => ['www.acme.test'], 'current_release' => '20261108-1200', 'status' => 'active', 'deploy_key_private' => null, 'deploy_key_public' => null],
            ['ref' => 'a2', 'server' => 's1', 'source_provider' => 'gl', 'name' => 'api', 'slug' => 'acme-api', 'type' => 'php', 'framework' => 'laravel', 'repository_url' => 'https://gitlab.com/acme/api', 'repository_id' => '42', 'env_variables' => null, 'system_user' => 'coresmith-app-api', 'base_port' => 8120, 'php_version' => '8.5.5', 'status' => 'active'],
            ['ref' => 'a3', 'server' => 's1', 'source_provider' => 'cu', 'name' => 'docs', 'slug' => 'acme-docs', 'type' => 'php', 'framework' => 'laravel', 'repository_url' => 'git@git.example.org:acme/docs.git', 'system_user' => 'coresmith-app-docs', 'base_port' => 8140, 'php_version' => '8.5.5', 'status' => 'active', 'deploy_key_private' => "-----BEGIN OPENSSH PRIVATE KEY-----\ndocs-deploy-key\n-----END OPENSSH PRIVATE KEY-----", 'deploy_key_public' => 'ssh-ed25519 AAAAdocs'],
        ],
        'webhook_endpoints' => [['application' => 'a1', 'secret' => 'hook-secret-moves', 'provider' => 'github', 'active' => true]],
        'databases' => [['server' => 's2', 'type' => 'mysql', 'name' => 'acme', 'db_user' => 'acme', 'db_password' => 'database-password-moves', 'credentials_shown_at' => '2026-10-01 09:30:00', 'host' => '127.0.0.1', 'port' => 3306, 'status' => 'active', 'privilege_level' => 'full', 'extensions' => [], 'is_external' => false, 'last_offsite_at' => null, 'last_offsite_key' => null, 'last_offsite_error' => null]],
        'backup_destination' => ['endpoint' => 'https://s3.eu-central-1.amazonaws.com', 'region' => 'eu-central-1', 'bucket' => 'acme-backups', 'prefix' => 'nightly', 'access_key_id' => 'AKIAMOVES', 'secret_access_key' => 's3-secret-moves', 'path_style' => false, 'last_run_at' => null],
    ], $overrides);
}

function teamImporterCode(User $owner): MoveCode
{
    return app(MoveCodes::class)->create($owner);
}

function teamImporterRefusal(array $payload, MoveCode $code, User $owner): string
{
    try {
        app(TeamImporter::class)->import($payload, $code, $owner);
    } catch (ValidationException $e) {
        return (string) collect($e->errors())->flatten()->first();
    }

    throw new RuntimeException('Expected the import to be refused.');
}

beforeEach(function () {
    Queue::fake([TakeOverServer::class]);
    Notification::fake();
});

test('importing recreates the team with its servers waiting to be taken over', function () {
    $owner = User::factory()->create(['email' => 'owner@acme.test']);
    $code = teamImporterCode($owner);
    $payload = teamImporterPayload();

    $team = app(TeamImporter::class)->import($payload, $code, $owner);
    $web = $team->servers()->where('name', 'web-01')->first();
    $site = $team->applications()->where('slug', 'acme-site')->first();

    expect($team->name)->toBe('Acme')
        ->and($team->slug)->toBe('acme')
        ->and($team->owner_id)->toBe($owner->id)
        ->and($team->members()->whereKey($owner->id)->first()?->pivot->role)->toBe(TeamRole::Owner->value)
        ->and($owner->fresh()->current_team_id)->toBe($team->id)
        ->and($team->servers()->count())->toBe(2)
        ->and($web->status)->toBe(ServerStatus::Moving)
        ->and($web->takeover)->toMatchArray(['state' => 'waiting', 'cloud_key' => $payload['servers'][0]['ssh_public_key'], 'attempts' => 0])
        ->and($web->ssh_private_key)->toBe($payload['servers'][0]['ssh_private_key'])
        ->and($web->getRawOriginal('ssh_private_key'))->not->toContain('PRIVATE KEY')
        ->and($web->cloudProvider?->name)->toBe('Hetzner')
        ->and($web->cloudProvider?->api_token)->toBe('hetzner-token-moves')
        ->and($web->provider_server_id)->toBe('4711')
        ->and($web->host_key_fingerprint)->toBe('SHA256:webkey')
        ->and($team->servers()->where('name', 'db-01')->first()->ssh_port)->toBe(2222)
        ->and($site->server_id)->toBe($web->id)
        ->and($site->env_variables)->toContain('DB_PASSWORD=site-db-password')
        ->and($site->processes)->toBe([['name' => 'queue', 'command' => 'php artisan queue:work']])
        ->and($site->current_release)->toBe('20261108-1200')
        ->and($site->system_user)->toBe('coresmith-app-site')
        ->and($site->webhookEndpoints()->first()?->secret)->toBe('hook-secret-moves')
        ->and($team->applications()->where('slug', 'acme-docs')->first()->deploy_key_private)->toContain('docs-deploy-key')
        ->and(ManagedDatabase::query()->where('team_id', $team->id)->first()?->db_password)->toBe('database-password-moves')
        ->and(ManagedDatabase::query()->where('team_id', $team->id)->first()?->credentials_shown_at?->toDateTimeString())->toBe('2026-10-01 09:30:00')
        ->and(ManagedDatabase::query()->where('team_id', $team->id)->first()?->server_id)->toBe($team->servers()->where('name', 'db-01')->first()->id)
        ->and($team->backupDestination?->secret_access_key)->toBe('s3-secret-moves')
        ->and($code->fresh()->imported_at)->not->toBeNull()
        ->and($code->fresh()->team_id)->toBe($team->id)
        ->and($code->fresh()->secret_key)->toBeNull()
        ->and($code->fresh()->file)->toBeNull();

    Queue::assertPushed(TakeOverServer::class, 2);
});

test('teammates are invited with their roles and the importer is not', function () {
    $owner = User::factory()->create(['email' => 'ada@acme.test']);

    $team = app(TeamImporter::class)->import(teamImporterPayload(), teamImporterCode($owner), $owner);

    expect(TeamInvitation::query()->where('team_id', $team->id)->orderBy('email')->get(['email', 'role'])->map(fn(TeamInvitation $invitation): array => [$invitation->email, $invitation->role->value])->all())
        ->toBe([['lin@acme.test', 'member']]);
});

test('apps on coresmith cloud\'s github app wait on a connection that never works until github is connected here', function () {
    $owner = User::factory()->create();

    $team = app(TeamImporter::class)->import(teamImporterPayload(), teamImporterCode($owner), $owner);
    $site = $team->applications()->where('slug', 'acme-site')->first();
    $placeholder = $site->sourceProvider;
    $connected = SourceProvider::factory()->githubApp(GitHubApp::factory()->create(['team_id' => $team->id]))->create(['team_id' => $team->id]);

    expect($placeholder->type)->toBe(SourceProviderType::GitHubApp)
        ->and($placeholder->name)->toBe('Coresmith Cloud')
        ->and($placeholder->connected())->toBeFalse()
        ->and($team->applications()->where('slug', 'acme-api')->first()->sourceProvider->connected())->toBeFalse()
        ->and($team->applications()->where('slug', 'acme-docs')->first()->sourceProvider->connected())->toBeTrue()
        ->and(app(ApplicationRelinker::class)->stranded($connected)->pluck('slug')->all())->toBe(['acme-site']);
});

test('the team keeps its address when it is free and gets a new one when it is taken', function () {
    $owner = User::factory()->create();
    Team::factory()->create(['slug' => 'acme']);

    $team = app(TeamImporter::class)->import(teamImporterPayload(), teamImporterCode($owner), $owner);

    expect($team->slug)->not->toBe('acme')->and($team->slug)->toStartWith('acme');
});

test('a server already on this panel stops the import and nothing is created', function () {
    $owner = User::factory()->create();
    Server::factory()->create(['ip_address' => '203.0.113.11']);
    $teams = Team::query()->count();

    expect(teamImporterRefusal(teamImporterPayload(), teamImporterCode($owner), $owner))
        ->toBe('203.0.113.11 is already a server here. Remove it first, then upload the file again.')
        ->and(Team::query()->count())->toBe($teams);
});

test('an app whose address is taken on this panel stops the import', function () {
    $owner = User::factory()->create();
    Application::factory()->create(['slug' => 'acme-docs']);

    expect(teamImporterRefusal(teamImporterPayload(), teamImporterCode($owner), $owner))
        ->toBe('An app called acme-docs is already here. Remove it first, then upload the file again.');
});

test('the preview says what moves and what needs reconnecting', function () {
    expect(app(TeamImporter::class)->preview(teamImporterPayload()))->toBe([
        'team' => 'Acme',
        'from' => 'https://app.coresmith.io',
        'servers' => ['web-01', 'db-01'],
        'applications' => 3,
        'databases' => 1,
        'members' => 2,
        'github' => 1,
        'reconnect' => ['GitLab' => 1],
        'webhooks' => 1,
    ]);
});
