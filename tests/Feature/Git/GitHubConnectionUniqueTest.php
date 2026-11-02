<?php

declare(strict_types=1);

use App\Models\Application;
use App\Models\GitHubApp;
use App\Models\SourceProvider;
use App\Models\Team;
use App\Services\Git\GitHub\GitHubAppCredentials;
use App\Services\Git\GitHub\GitHubApps;
use App\Services\Git\GitHub\GitHubConnector;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Sleep;

function uniqueLinkMigration(): object
{
    return require base_path('database/migrations/2026_11_02_062907_make_github_connections_unique.php');
}

function uniqueLinkApp(Team $team, bool $env): GitHubAppCredentials
{
    if (!$env) {
        return app(GitHubApps::class)->fromModel(GitHubApp::factory()->for($team)->create());
    }

    openssl_pkey_export(openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]), $pem);
    config(['services.github' => ['app_id' => '1041207', 'app_slug' => 'coresmith', 'client_id' => 'Iv1.env', 'client_secret' => 'env-secret', 'private_key' => $pem, 'webhook_secret' => null]]);

    return app(GitHubApps::class)->env();
}

function uniqueLinkConnection(Team $team, ?string $githubAppId, array $attributes = []): SourceProvider
{
    return SourceProvider::factory()->for($team)->create([
        'type' => 'github_app',
        'github_app_id' => $githubAppId,
        'name' => 'monoventech',
        'installation_id' => '51842',
        'account_type' => 'Organization',
        ...$attributes,
    ]);
}

beforeEach(fn() => Http::preventStrayRequests());

test('a chooser posted twice at once links one connection', function (bool $env) {
    $team = Team::factory()->create();
    $app = uniqueLinkApp($team, $env);
    $installation = ['id' => '51842', 'account' => 'monoventech', 'account_type' => 'Organization', 'repository_selection' => 'all', 'suspended_at' => null];
    $first = Cache::lock(GitHubConnector::linkLock($team, $app, '51842'), GitHubConnector::LINK_LOCK_SECONDS);
    $first->get();
    $firstLinked = false;
    $finishFirst = function () use (&$firstLinked, $first, $team, $app): void {
        if ($firstLinked) {
            return;
        }

        $firstLinked = true;
        uniqueLinkConnection($team, $app->isEnv() ? null : $app->key, ['repository_selection' => 'all', 'access_token' => null]);
        $first->release();
    };
    Sleep::fake();
    Sleep::whenFakingSleep(fn() => $finishFirst());

    $second = app(GitHubConnector::class)->link($team, $app, $installation);
    $finishFirst();

    expect(SourceProvider::withTrashed()->where('team_id', $team->id)->count())->toBe(1)
        ->and(SourceProvider::withTrashed()->where('team_id', $team->id)->sole()->id)->toBe($second->id);
})->with([
    'a registered app' => [false],
    'the environment app' => [true],
]);

test('the database refuses a second connection of one team for the same app and installation, removed or not', function (bool $removed) {
    $team = Team::factory()->create();
    $app = GitHubApp::factory()->for($team)->create();
    uniqueLinkConnection($team, $app->id, $removed ? ['deleted_at' => now()] : []);

    expect(fn() => DB::transaction(fn() => uniqueLinkConnection($team, $app->id)))->toThrow(UniqueConstraintViolationException::class);

    uniqueLinkConnection(Team::factory()->create(), $app->id);
    uniqueLinkConnection($team, GitHubApp::factory()->for($team)->create()->id);
    uniqueLinkConnection($team, $app->id, ['installation_id' => '51843']);
    uniqueLinkConnection($team, null);

    expect(SourceProvider::withTrashed()->count())->toBe(5);
})->with([
    'a live connection' => [false],
    'a removed connection' => [true],
]);

test('connections without an installation, such as gitlab, are not held to it', function () {
    $team = Team::factory()->create();

    SourceProvider::factory()->for($team)->create(['type' => 'gitlab', 'installation_id' => null, 'deleted_at' => now()]);
    SourceProvider::factory()->for($team)->create(['type' => 'gitlab', 'installation_id' => null]);

    expect(SourceProvider::withTrashed()->where('type', 'gitlab')->count())->toBe(2);
});

test('the migration merges existing duplicates onto the oldest connection and moves their apps to it', function () {
    $migration = uniqueLinkMigration();
    $migration->down();
    $team = Team::factory()->create();
    $app = GitHubApp::factory()->for($team)->create();
    $oldest = uniqueLinkConnection($team, $app->id, ['created_at' => now()->subDays(3), 'deleted_at' => now()->subDays(2)]);
    $newer = uniqueLinkConnection($team, $app->id, ['created_at' => now()->subDays(2)]);
    $newest = uniqueLinkConnection($team, $app->id, ['created_at' => now()->subDay()]);
    $envOldest = uniqueLinkConnection($team, null, ['installation_id' => '61842', 'created_at' => now()->subDays(2)]);
    $envNewer = uniqueLinkConnection($team, null, ['installation_id' => '61842', 'created_at' => now()->subDay()]);
    $alone = uniqueLinkConnection($team, $app->id, ['installation_id' => '51843']);
    $otherApp = uniqueLinkConnection($team, $app->id, ['installation_id' => '61842']);
    $elsewhere = uniqueLinkConnection(Team::factory()->create(), $app->id);
    $onNewer = Application::factory()->for($team)->create(['source_provider_id' => $newer->id, 'repository_id' => '70000001']);
    $onNewest = Application::factory()->for($team)->create(['source_provider_id' => $newest->id, 'repository_id' => '70000002']);
    $onEnv = Application::factory()->for($team)->create(['source_provider_id' => $envNewer->id, 'repository_id' => '70000003']);
    $onAlone = Application::factory()->for($team)->create(['source_provider_id' => $alone->id, 'repository_id' => '70000004']);

    $migration->up();

    expect(SourceProvider::withTrashed()->pluck('id')->sort()->values()->all())->toBe(collect([$oldest->id, $envOldest->id, $alone->id, $otherApp->id, $elsewhere->id])->sort()->values()->all())
        ->and(SourceProvider::query()->find($oldest->id))->not->toBeNull()
        ->and($onNewer->fresh()->source_provider_id)->toBe($oldest->id)
        ->and($onNewest->fresh()->source_provider_id)->toBe($oldest->id)
        ->and($onEnv->fresh()->source_provider_id)->toBe($envOldest->id)
        ->and($onAlone->fresh()->source_provider_id)->toBe($alone->id);

    expect(fn() => uniqueLinkConnection($team, $app->id))->toThrow(UniqueConstraintViolationException::class);
});

test('a group of removed duplicates stays removed when it is merged', function () {
    $migration = uniqueLinkMigration();
    $migration->down();
    $team = Team::factory()->create();
    $app = GitHubApp::factory()->for($team)->create();
    $oldest = uniqueLinkConnection($team, $app->id, ['created_at' => now()->subDays(2), 'deleted_at' => now()->subDay()]);
    uniqueLinkConnection($team, $app->id, ['created_at' => now()->subDay(), 'deleted_at' => now()]);

    $migration->up();

    expect(SourceProvider::withTrashed()->sole()->id)->toBe($oldest->id)
        ->and(SourceProvider::withTrashed()->sole()->trashed())->toBeTrue();
});

test('the unique migration reverses cleanly', function () {
    $migration = uniqueLinkMigration();
    $team = Team::factory()->create();
    $app = GitHubApp::factory()->for($team)->create();

    $migration->down();

    uniqueLinkConnection($team, $app->id);
    uniqueLinkConnection($team, $app->id);

    expect(SourceProvider::count())->toBe(2)
        ->and(collect(Schema::getIndexes('source_providers'))->pluck('name'))->not->toContain('source_providers_github_installation_unique');

    $migration->up();

    expect(SourceProvider::count())->toBe(1)
        ->and(collect(Schema::getIndexes('source_providers'))->firstWhere('name', 'source_providers_github_installation_unique'))
        ->toMatchArray(['columns' => ['team_id', 'type', 'github_app_id', 'installation_id'], 'unique' => true]);
});

test('the team keeps an index of its own, so the unique index can be dropped while the foreign key stays', function () {
    $migration = uniqueLinkMigration();

    expect(Schema::hasIndex('source_providers', 'source_providers_team_id_index'))->toBeTrue();

    $migration->down();

    expect(Schema::hasIndex('source_providers', 'source_providers_github_installation_unique'))->toBeFalse()
        ->and(Schema::hasIndex('source_providers', 'source_providers_team_id_index'))->toBeTrue();

    $migration->up();

    expect(collect(Schema::getIndexes('source_providers'))->where('name', 'source_providers_team_id_index')->count())->toBe(1)
        ->and(Schema::hasIndex('source_providers', 'source_providers_github_installation_unique'))->toBeTrue();
});

test('a restored oldest row takes the state of the newest duplicate that was still connected', function () {
    $migration = uniqueLinkMigration();
    $migration->down();
    $team = Team::factory()->create();
    $app = GitHubApp::factory()->for($team)->create();
    $created = now()->subDays(3)->startOfSecond();
    $oldest = uniqueLinkConnection($team, $app->id, ['name' => 'monoventech-old', 'repository_selection' => 'selected', 'suspended_at' => now()->subDays(2), 'access_token' => null, 'token_expires_at' => null, 'created_at' => $created, 'deleted_at' => now()->subDays(2)]);
    uniqueLinkConnection($team, $app->id, ['name' => 'monoventech-mid', 'repository_selection' => 'selected', 'access_token' => 'ghs_middle', 'created_at' => now()->subDays(2)]);
    uniqueLinkConnection($team, $app->id, ['name' => 'monoventech', 'repository_selection' => 'all', 'suspended_at' => null, 'access_token' => 'ghs_newest', 'refresh_token' => null, 'token_expires_at' => now()->addHour()->startOfSecond(), 'created_at' => now()->subDay()]);
    uniqueLinkConnection($team, $app->id, ['name' => 'monoventech-gone', 'access_token' => 'ghs_removed', 'created_at' => now()->subHour(), 'deleted_at' => now()->subMinute()]);

    $migration->up();

    $kept = SourceProvider::withTrashed()->sole();

    expect($kept->id)->toBe($oldest->id)
        ->and($kept->created_at->equalTo($created))->toBeTrue()
        ->and($kept->trashed())->toBeFalse()
        ->and($kept->name)->toBe('monoventech')
        ->and($kept->repository_selection)->toBe('all')
        ->and($kept->suspended_at)->toBeNull()
        ->and($kept->access_token)->toBe('ghs_newest')
        ->and($kept->token_expires_at?->equalTo(now()->addHour()->startOfSecond()))->toBeTrue();
});

test('an oldest row that was still connected keeps its own state when its duplicates are merged', function () {
    $migration = uniqueLinkMigration();
    $migration->down();
    $team = Team::factory()->create();
    $app = GitHubApp::factory()->for($team)->create();
    $oldest = uniqueLinkConnection($team, $app->id, ['name' => 'monoventech', 'repository_selection' => 'selected', 'access_token' => 'ghs_oldest', 'created_at' => now()->subDays(2)]);
    uniqueLinkConnection($team, $app->id, ['name' => 'monoventech-newer', 'repository_selection' => 'all', 'access_token' => 'ghs_newer', 'created_at' => now()->subDay()]);

    $migration->up();

    expect(SourceProvider::withTrashed()->sole())
        ->id->toBe($oldest->id)
        ->name->toBe('monoventech')
        ->repository_selection->toBe('selected')
        ->access_token->toBe('ghs_oldest');
});
