<?php

declare(strict_types=1);

use App\Enums\ApplicationStatus;
use App\Enums\ApplicationType;
use App\Enums\DeploymentStatus;
use App\Models\Application;
use App\Models\Deployment;
use App\Models\GitHubApp;
use App\Models\SourceProvider;
use App\Models\User;
use App\Models\WebhookEndpoint;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('an application belongs to a server and a team', function () {
    $app = Application::factory()->create();

    expect($app->server)->not->toBeNull()->and($app->team)->not->toBeNull();
});

test('an application slug is unique', function () {
    Application::factory()->create(['slug' => 'my-app']);

    Application::factory()->create(['slug' => 'my-app']);
})->throws(QueryException::class);

test('env variables are encrypted at rest', function () {
    $app = Application::factory()->create(['env_variables' => '{"APP_KEY":"secret"}']);

    expect(DB::table('applications')->where('id', $app->id)->value('env_variables'))->not->toContain('secret')
        ->and($app->fresh()->env_variables)->toBe('{"APP_KEY":"secret"}');
});

test('application enums cast correctly', function () {
    $app = Application::factory()->create(['type' => ApplicationType::Php, 'status' => ApplicationStatus::Active]);

    expect($app->fresh()->type)->toBe(ApplicationType::Php)
        ->and($app->fresh()->status)->toBe(ApplicationStatus::Active);
});

test('an application has many deployments', function () {
    $app = Application::factory()->create();
    Deployment::factory()->count(3)->for($app)->create();

    expect($app->deployments)->toHaveCount(3);
});

test('a deployment records who triggered it', function () {
    $user = User::factory()->create();
    $deployment = Deployment::factory()->for($user, 'triggeredBy')->create();

    expect($deployment->triggeredBy->id)->toBe($user->id)
        ->and($deployment->fresh()->status)->toBe(DeploymentStatus::Pending);
});

test('a webhook secret is encrypted at rest', function () {
    $endpoint = WebhookEndpoint::factory()->create(['secret' => 'sh-top-secret']);

    expect(DB::table('webhook_endpoints')->where('id', $endpoint->id)->value('secret'))->not->toContain('sh-top-secret')
        ->and($endpoint->fresh()->secret)->toBe('sh-top-secret');
});

function applicationLinked(?SourceProvider $connection = null, array $attributes = []): Application
{
    $connection ??= SourceProvider::factory()->githubApp()->create();

    return Application::factory()->for($connection->team)->create(array_merge([
        'repository_url' => 'https://github.com/monoventech/atlas.git',
        'repository_provider' => 'github',
        'source_provider_id' => $connection->id,
        'repository_id' => '70000001',
    ], $attributes));
}

function applicationWithoutEnvironmentApp(): void
{
    config(['services.github' => ['app_id' => null, 'app_slug' => 'coresmith', 'client_id' => null, 'client_secret' => null, 'private_key' => null, 'webhook_secret' => null]]);
}

test('an application remembers the github connection and repository it was picked from', function () {
    $connection = SourceProvider::factory()->githubApp()->create(['name' => 'monoventech']);
    $application = applicationLinked($connection)->fresh();

    expect($application->source_provider_id)->toBe($connection->id)
        ->and($application->repository_id)->toBe('70000001')
        ->and($application->sourceProvider->is($connection))->toBeTrue()
        ->and($application->githubConnection()?->is($connection))->toBeTrue();
});

test('the connection stays readable after it is disconnected so the app can say what happened', function () {
    $connection = SourceProvider::factory()->githubApp()->create();
    $application = applicationLinked($connection);

    $connection->delete();
    $application = $application->fresh();

    expect($application->sourceProvider)->not->toBeNull()
        ->and($application->sourceProvider->trashed())->toBeTrue()
        ->and($application->githubConnection()?->is($connection))->toBeTrue()
        ->and($application->githubConnection()?->trashed())->toBeTrue();
});

test('an application with no link has no github connection and asks the database nothing', function () {
    $application = Application::factory()->make(['source_provider_id' => null]);

    DB::flushQueryLog();
    DB::enableQueryLog();

    expect($application->githubConnection())->toBeNull()
        ->and($application->usesGitHubToken())->toBeFalse()
        ->and(DB::getQueryLog())->toBe([]);
});

test('only a github app connection counts as the github connection', function () {
    applicationWithoutEnvironmentApp();
    $gitlab = SourceProvider::factory()->create(['type' => 'gitlab', 'access_token' => 'glpat']);
    $application = applicationLinked($gitlab);

    expect($application->sourceProvider->is($gitlab))->toBeTrue()
        ->and($application->githubConnection())->toBeNull()
        ->and($application->usesGitHubToken())->toBeFalse();
});

test('an application clones with a github token only while its connection is live and its app exists', function (string $state, bool $expected) {
    applicationWithoutEnvironmentApp();
    $connection = SourceProvider::factory()->githubApp()->create();
    $application = applicationLinked($connection);

    match ($state) {
        'live' => null,
        'disconnected' => $connection->delete(),
        'suspended' => $connection->markSuspended(now()),
        'no installation' => $connection->forceFill(['installation_id' => null])->save(),
        'app removed' => GitHubApp::query()->delete(),
        'environment app' => (function () use ($connection): void {
            GitHubApp::query()->delete();
            $connection->forceFill(['github_app_id' => null])->save();
            config(['services.github' => [
                'app_id' => '1041207', 'app_slug' => 'coresmith', 'client_id' => 'Iv1.env', 'client_secret' => 'env-secret',
                'private_key' => "-----BEGIN PRIVATE KEY-----\nx\n-----END PRIVATE KEY-----", 'webhook_secret' => null,
            ]]);
        })(),
    };

    expect($application->fresh()->usesGitHubToken())->toBe($expected);
})->with([
    'live' => ['live', true],
    'disconnected' => ['disconnected', false],
    'suspended' => ['suspended', false],
    'no installation' => ['no installation', false],
    'app removed' => ['app removed', false],
    'environment app' => ['environment app', true],
]);

test('an application names one problem with its github connection, the same way everywhere', function (string $state, ?string $expected) {
    applicationWithoutEnvironmentApp();
    $connection = SourceProvider::factory()->githubApp()->create();
    $application = applicationLinked($connection);

    match ($state) {
        'live' => null,
        'not linked' => $application->forceFill(['source_provider_id' => null])->save(),
        'disconnected' => $connection->delete(),
        'suspended' => $connection->markSuspended(now()),
        'suspended then disconnected' => (function () use ($connection): void {
            $connection->markSuspended(now());
            $connection->delete();
        })(),
        'no installation' => $connection->forceFill(['installation_id' => null])->save(),
        'app removed' => GitHubApp::query()->delete(),
    };

    expect($application->fresh()->githubProblem())->toBe($expected);
})->with([
    'live' => ['live', null],
    'not linked' => ['not linked', null],
    'disconnected' => ['disconnected', 'removed'],
    'suspended' => ['suspended', 'suspended'],
    'suspended then disconnected' => ['suspended then disconnected', 'removed'],
    'no installation' => ['no installation', 'removed'],
    'app removed' => ['app removed', 'removed'],
]);

test('the repository name comes from the stored address', function (?string $url, ?string $expected) {
    expect(Application::factory()->make(['repository_url' => $url])->repositoryFullName())->toBe($expected);
})->with([
    'https with git suffix' => ['https://github.com/monoventech/atlas.git', 'monoventech/atlas'],
    'https without suffix' => ['https://github.com/monoventech/atlas', 'monoventech/atlas'],
    'ssh' => ['git@github.com:monoventech/atlas.git', 'monoventech/atlas'],
    'nothing' => [null, null],
    'not an address' => ['atlas', null],
]);

test('the link columns are indexed, carry no foreign key and reverse cleanly', function () {
    $migration = require base_path('database/migrations/2026_10_18_200200_link_applications_to_repositories.php');
    $added = ['source_provider_id', 'repository_id'];
    $columns = collect(Schema::getColumns('applications'))->keyBy('name');

    expect(Schema::hasColumns('applications', $added))->toBeTrue()
        ->and($columns['source_provider_id']['nullable'])->toBeTrue()
        ->and($columns['repository_id']['nullable'])->toBeTrue()
        ->and(Schema::hasIndex('applications', ['source_provider_id']))->toBeTrue()
        ->and(collect(Schema::getForeignKeys('applications'))->pluck('columns')->flatten()->all())->not->toContain('source_provider_id');

    $migration->down();

    expect(array_filter($added, fn(string $column): bool => Schema::hasColumn('applications', $column)))->toBe([])
        ->and(Schema::hasIndex('applications', ['source_provider_id']))->toBeFalse();

    $migration->up();

    expect(Schema::hasColumns('applications', $added))->toBeTrue()
        ->and(Schema::hasIndex('applications', ['source_provider_id']))->toBeTrue();
});
