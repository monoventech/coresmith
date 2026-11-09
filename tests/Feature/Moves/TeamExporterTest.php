<?php

declare(strict_types=1);

use App\Enums\DeploymentStatus;
use App\Enums\ServerStatus;
use App\Enums\SourceProviderType;
use App\Enums\TeamRole;
use App\Models\Application;
use App\Models\BackupDestination;
use App\Models\CloudProvider;
use App\Models\Deployment;
use App\Models\ManagedDatabase;
use App\Models\Server;
use App\Models\SourceProvider;
use App\Models\Team;
use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Services\Cloud\Tasks\CloudTask;
use App\Services\Moves\TeamExporter;
use Illuminate\Validation\ValidationException;

function teamExporterRefusal(Team $team): string
{
    try {
        app(TeamExporter::class)->payload($team, $team->owner);
    } catch (ValidationException $e) {
        return (string) collect($e->errors())->flatten()->first();
    }

    throw new RuntimeException('Expected the export to be refused.');
}

function teamExporterTeam(): Team
{
    $team = Team::factory()->create(['name' => 'Acme', 'slug' => 'acme', 'stripe_id' => 'cus_never_moves']);
    $team->members()->attach(User::factory()->create(['email' => 'ada@acme.test']), ['role' => TeamRole::Admin->value]);
    $team->members()->attach(User::factory()->create(['email' => 'lin@acme.test']), ['role' => TeamRole::Member->value]);

    $hetzner = CloudProvider::factory()->for($team)->create(['name' => 'Hetzner', 'api_token' => 'hetzner-token-moves']);
    $server = Server::factory()->active()->fromProvider($hetzner)->create([
        'name' => 'web-01',
        'ip_address' => '203.0.113.10',
        'host_key_fingerprint' => 'SHA256:webkey',
        'provisioning_phase' => 8,
        'installed_runtimes' => ['php' => ['8.5.5']],
    ]);
    $github = SourceProvider::factory()->for($team)->create(['type' => SourceProviderType::GitHubApp->value, 'access_token' => 'ghs_never_moves']);
    $custom = SourceProvider::factory()->for($team)->create(['type' => SourceProviderType::Custom->value, 'name' => 'Our Gitea', 'access_token' => null, 'installation_id' => null]);

    $site = Application::factory()->for($team)->for($server)->create([
        'name' => 'site',
        'source_provider_id' => $github->id,
        'repository_id' => '123456',
        'env_variables' => "APP_KEY=base64:secret-site\nDB_PASSWORD=site-db-password",
        'processes' => [['name' => 'queue', 'command' => 'php artisan queue:work']],
        'cron_jobs' => [['command' => 'php artisan schedule:run', 'schedule' => '* * * * *']],
        'additional_domains' => ['www.acme.test'],
        'current_release' => '20261108-1200',
    ]);
    Application::factory()->for($team)->for($server)->create([
        'name' => 'docs',
        'source_provider_id' => $custom->id,
        'deploy_key_private' => "-----BEGIN OPENSSH PRIVATE KEY-----\ndocs-deploy-key\n-----END OPENSSH PRIVATE KEY-----",
        'deploy_key_public' => 'ssh-ed25519 AAAAdocs',
    ]);
    WebhookEndpoint::factory()->create(['application_id' => $site->id, 'secret' => 'hook-secret-moves']);
    ManagedDatabase::factory()->create(['team_id' => $team->id, 'server_id' => $server->id, 'name' => 'acme', 'db_password' => 'database-password-moves', 'credentials_shown_at' => '2026-10-01 09:30:00']);
    BackupDestination::factory()->create(['team_id' => $team->id, 'bucket' => 'acme-backups', 'access_key_id' => 'AKIAMOVES', 'secret_access_key' => 's3-secret-moves']);

    return $team->fresh();
}

test('the export carries the team, its servers, apps and databases with their secrets in the clear', function () {
    config(['app.url' => 'https://app.coresmith.io']);
    $team = teamExporterTeam();

    $payload = app(TeamExporter::class)->payload($team, $team->owner);
    $server = $payload['servers'][0];
    $site = collect($payload['applications'])->firstWhere('name', 'site');
    $docs = collect($payload['applications'])->firstWhere('name', 'docs');

    expect($payload['team'])->toBe(['name' => 'Acme', 'slug' => 'acme'])
        ->and($payload['from'])->toBe('https://app.coresmith.io')
        ->and($payload['id'])->toBeString()->not->toBeEmpty()
        ->and(collect($payload['members'])->sortBy('email')->values()->all())->toBe([['email' => 'ada@acme.test', 'role' => 'admin'], ['email' => 'lin@acme.test', 'role' => 'member']])
        ->and($server)->toMatchArray(['name' => 'web-01', 'ip_address' => '203.0.113.10', 'ssh_user' => 'root', 'host_key_fingerprint' => 'SHA256:webkey', 'provisioning_phase' => 8, 'installed_runtimes' => ['php' => ['8.5.5']], 'provider_size' => 'cx22'])
        ->and($server['ssh_private_key'])->toContain('OPENSSH PRIVATE KEY')
        ->and($server['cloud_provider'])->toBe($payload['cloud_providers'][0]['ref'])
        ->and($payload['cloud_providers'][0])->toMatchArray(['name' => 'Hetzner', 'api_token' => 'hetzner-token-moves'])
        ->and($site)->toMatchArray(['server' => $server['ref'], 'repository_id' => '123456', 'current_release' => '20261108-1200', 'additional_domains' => ['www.acme.test']])
        ->and($site['env_variables'])->toContain('DB_PASSWORD=site-db-password')
        ->and($site['processes'])->toBe([['name' => 'queue', 'command' => 'php artisan queue:work']])
        ->and($docs['deploy_key_private'])->toContain('docs-deploy-key')
        ->and($payload['webhook_endpoints'][0])->toMatchArray(['application' => $site['ref'], 'secret' => 'hook-secret-moves'])
        ->and($payload['databases'][0])->toMatchArray(['server' => $server['ref'], 'name' => 'acme', 'db_password' => 'database-password-moves'])
        ->and($payload['databases'][0]['credentials_shown_at'])->not->toBeNull()
        ->and($payload['backup_destination'])->toMatchArray(['bucket' => 'acme-backups', 'access_key_id' => 'AKIAMOVES', 'secret_access_key' => 's3-secret-moves']);
});

test('the export names how each app reaches its repository but never carries a git sign-in', function () {
    $team = teamExporterTeam();

    $payload = app(TeamExporter::class)->payload($team, $team->owner);
    $providers = collect($payload['source_providers'])->keyBy('ref');
    $site = collect($payload['applications'])->firstWhere('name', 'site');
    $docs = collect($payload['applications'])->firstWhere('name', 'docs');

    expect($providers[$site['source_provider']])->toBe(['ref' => $site['source_provider'], 'type' => 'github_app', 'name' => $providers[$site['source_provider']]['name'], 'custom_host' => null])
        ->and($providers[$docs['source_provider']])->toMatchArray(['type' => 'custom', 'name' => 'Our Gitea', 'custom_host' => null])
        ->and(json_encode($payload))->not->toContain('ghs_never_moves');
});

test('the export leaves billing, history and sign-in settings behind', function () {
    $team = teamExporterTeam();
    Deployment::factory()->succeeded()->create(['application_id' => $team->applications()->first()->id, 'commit_message' => 'history stays behind']);

    $json = (string) json_encode(app(TeamExporter::class)->payload($team, $team->owner));

    expect($json)->not->toContain('cus_never_moves')
        ->and($json)->not->toContain('history stays behind')
        ->and(array_keys(app(TeamExporter::class)->payload($team, $team->owner)))->toBe(['id', 'made_at', 'from', 'team', 'members', 'cloud_providers', 'servers', 'source_providers', 'applications', 'webhook_endpoints', 'databases', 'backup_destination']);
});

test('a server that is not ready stops the export', function (ServerStatus $status) {
    $team = teamExporterTeam();
    $team->servers()->first()->forceFill(['status' => $status])->save();

    expect(teamExporterRefusal($team))->toBe("web-01 isn't ready to move. Finish setting it up or remove it, then download the move file.");
})->with([ServerStatus::Pending, ServerStatus::Provisioning, ServerStatus::Failed, ServerStatus::Creating, ServerStatus::Disconnected]);

test('a server whose host key changed stops the export', function () {
    $team = teamExporterTeam();
    $team->servers()->first()->forceFill(['pending_host_key_fingerprint' => 'SHA256:other'])->save();

    expect(teamExporterRefusal($team))->toBe("web-01's host key changed. Accept or reject the new key on its page, then download the move file.");
});

test('a server being resized at its provider stops the export', function () {
    $team = teamExporterTeam();
    CloudTask::start($team->servers()->first(), CloudTask::RESIZE, 'resize');

    expect(teamExporterRefusal($team))->toBe("Coresmith is resizing web-01 at Hetzner Cloud. Download the move file once it's back.");
});

test('a running deploy stops the export', function () {
    $team = teamExporterTeam();
    Deployment::factory()->create(['application_id' => $team->applications()->where('name', 'site')->first()->id, 'status' => DeploymentStatus::Running]);

    expect(teamExporterRefusal($team))->toBe('site is deploying. Wait for it to finish, then download the move file.');
});
