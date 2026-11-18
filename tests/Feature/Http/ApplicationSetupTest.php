<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Enums\DatabaseType;
use App\Enums\ServerStatus;
use App\Enums\SourceProviderType;
use App\Enums\TeamRole;
use App\Http\Controllers\ApplicationSetupController;
use App\Models\Application;
use App\Models\ManagedDatabase;
use App\Models\Server;
use App\Models\SourceProvider;
use App\Models\Team;
use App\Models\User;
use App\Services\Deployment\DeployGuard;
use App\Services\Deployment\EnvironmentFile;
use App\Services\Detection\DetectionStore;
use App\Services\Detection\RepositoryAccess;
use App\Services\Detection\RepositoryRead;
use App\Services\Panel\ActivityFeed;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\RepositoryFixture;

/**
 * @return array{team: Team, owner: User, server: Server, shop: Application}
 */
function setupFixture(array $attributes = []): array
{
    $team = Team::factory()->owned()->create();
    $server = Server::factory()->for($team)->create(['name' => 'web']);

    return [
        'team' => $team,
        'owner' => $team->owner->fresh(),
        'server' => $server,
        'shop' => Application::factory()->for($team)->for($server)->create([
            'name' => 'shop',
            'type' => ApplicationType::Php,
            'framework' => 'laravel',
            'repository_url' => 'https://github.com/acme/shop.git',
            'repository_branch' => 'main',
            'directory' => '/',
            'web_directory' => '/public',
            'app_size' => 'small',
            'runtime_pins' => null,
            'source_provider_id' => null,
            ...$attributes,
        ]),
    ];
}

/**
 * @return array<string, mixed>
 */
function setupPayload(array $changes = []): array
{
    return [
        'type' => 'php',
        'framework' => 'laravel',
        'repository_url' => 'https://github.com/acme/shop.git',
        'repository_branch' => 'main',
        'directory' => '/',
        'web_directory' => '/public',
        'app_size' => 'small',
        ...$changes,
    ];
}

test("Edit setup opens Add an app's form filled in with the app's setup", function () {
    ['owner' => $owner, 'server' => $server, 'shop' => $shop] = setupFixture(['runtime_pins' => ['php' => '8.4']]);

    $this->actingAs($owner)->get(route('servers.applications.setup.edit', [$server, $shop]))
        ->assertOk()
        ->assertInertia(fn(AssertableInertia $page) => $page
            ->component('applications/Create')
            ->where('setup.action', route('servers.applications.setup.update', [$server, $shop]))
            ->where('setup.back', route('servers.applications.show', [$server, $shop]))
            ->where('setup.application', [
                'name' => 'shop',
                'source' => ['kind' => 'url', 'url' => 'https://github.com/acme/shop.git'],
                'branch' => 'main',
                'directory' => '/',
                'type' => 'php',
                'framework' => 'laravel',
                'js_runtime' => null,
                'web_directory' => '/public',
                'main_package' => null,
                'app_size' => 'small',
                'runtime_version' => '8.4',
                'database' => 'none',
                'redis' => 'none',
                'horizon' => false,
            ]));
});

/**
 * @return array<string, string>
 */
function setupVariables(Application $application): array
{
    return app(EnvironmentFile::class)->parse((string) $application->fresh()->env_variables);
}

test('Edit setup offers the databases on the server, with the ones the app uses picked', function () {
    ['owner' => $owner, 'server' => $server, 'shop' => $shop] = setupFixture(['stack' => ['packages' => ['horizon' => true]]]);
    $ledger = ManagedDatabase::factory()->for($server)->for($server->team)->postgres()->create(['name' => 'ledger', 'db_user' => 'ledger']);
    $cache = ManagedDatabase::factory()->for($server)->for($server->team)->redis()->create(['name' => 'shop_redis']);
    $shop->databases()->attach([$ledger->getKey(), $cache->getKey()]);

    $this->actingAs($owner)->get(route('servers.applications.setup.edit', [$server, $shop]))
        ->assertOk()
        ->assertInertia(fn(AssertableInertia $page) => $page
            ->where('setup.application.database', 'existing:' . $ledger->getKey())
            ->where('setup.application.redis', 'existing:' . $cache->getKey())
            ->where('setup.application.horizon', true)
            ->has('databases.choices', 2)
            ->has('databases.can_create'));
});

test('a Laravel app on SQLite reads as SQLite on Edit setup', function () {
    ['owner' => $owner, 'server' => $server, 'shop' => $shop] = setupFixture(['env_variables' => "DB_CONNECTION=sqlite\n"]);

    $this->actingAs($owner)->get(route('servers.applications.setup.edit', [$server, $shop]))
        ->assertInertia(fn(AssertableInertia $page) => $page->where('setup.application.database', 'sqlite'));
});

test('a new Redis picked on Edit setup is created and connected, and a Horizon app queues on it', function () {
    Queue::fake();
    ['owner' => $owner, 'server' => $server, 'shop' => $shop] = setupFixture(['stack' => ['packages' => ['horizon' => true]], 'env_variables' => "QUEUE_CONNECTION=database\n"]);
    $server->forceFill(['status' => ServerStatus::Active])->save();

    $this->actingAs($owner)->put(route('servers.applications.setup.update', [$server, $shop]), setupPayload(['redis' => 'new']))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('servers.applications.show', [$server, $shop]))
        ->assertSessionHas('success', "Saved. Coresmith is creating a Redis database for shop. Its settings are in the variables, so deploy once it's ready.");

    $redis = ManagedDatabase::query()->where('type', DatabaseType::Redis->value)->sole();

    expect($shop->fresh()->databases->modelKeys())->toBe([$redis->getKey()])
        ->and(setupVariables($shop))->toMatchArray(['REDIS_PORT' => (string) $redis->port, 'QUEUE_CONNECTION' => 'redis']);
});

test('switching to another database connects it and lets go of the old one, whose data stays there', function () {
    ['owner' => $owner, 'server' => $server, 'shop' => $shop] = setupFixture();
    $old = ManagedDatabase::factory()->for($server)->for($server->team)->postgres()->create(['name' => 'shop_old', 'db_user' => 'shop_old']);
    $new = ManagedDatabase::factory()->for($server)->for($server->team)->postgres()->create(['name' => 'shop_new', 'db_user' => 'shop_new']);
    $shop->databases()->attach($old->getKey());

    $this->actingAs($owner)->put(route('servers.applications.setup.update', [$server, $shop]), setupPayload(['database' => 'existing:' . $new->getKey()]))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', 'Saved. shop now uses shop_new, and shop_old keeps its data. Deploy to switch over.');

    expect($shop->fresh()->databases->modelKeys())->toBe([$new->getKey()])
        ->and(setupVariables($shop))->toMatchArray(['DB_DATABASE' => 'shop_new', 'DB_USERNAME' => 'shop_new'])
        ->and(Activity::query()->where('description', 'database_disconnected')->sole()->properties->all())->toMatchArray(['database' => 'shop_old']);
});

test('picking none lets go of the database and leaves its variables for you to manage', function () {
    ['owner' => $owner, 'server' => $server, 'shop' => $shop] = setupFixture(['env_variables' => "DB_CONNECTION=pgsql\nDB_DATABASE=ledger\n"]);
    $ledger = ManagedDatabase::factory()->for($server)->for($server->team)->postgres()->create(['name' => 'ledger', 'db_user' => 'ledger']);
    $shop->databases()->attach($ledger->getKey());

    $this->actingAs($owner)->put(route('servers.applications.setup.update', [$server, $shop]), setupPayload(['database' => 'none']))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', 'Saved. ledger is no longer connected to shop. Its variables stay as they are until you change them.');

    expect($shop->fresh()->databases)->toBeEmpty()
        ->and(setupVariables($shop))->toMatchArray(['DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => 'ledger']);
});

test('a Laravel app moved to SQLite on Edit setup gets DB_CONNECTION for the next deploy', function () {
    ['owner' => $owner, 'server' => $server, 'shop' => $shop] = setupFixture(['env_variables' => "APP_NAME=Shop\n"]);

    $this->actingAs($owner)->put(route('servers.applications.setup.update', [$server, $shop]), setupPayload(['database' => 'sqlite']))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', 'Saved. shop now uses SQLite, kept across deploys. Deploy to switch over.');

    expect(setupVariables($shop))->toMatchArray(['APP_NAME' => 'Shop', 'DB_CONNECTION' => 'sqlite']);
});

test('an app on SQLite that picks no database keeps its variables, and the message says what still points at SQLite', function () {
    ['owner' => $owner, 'server' => $server, 'shop' => $shop] = setupFixture(['env_variables' => "DB_CONNECTION=sqlite\n"]);

    $this->actingAs($owner)->put(route('servers.applications.setup.update', [$server, $shop]), setupPayload(['database' => 'none']))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', 'Saved. shop keeps using SQLite until you change DB_CONNECTION in its variables.');

    expect(setupVariables($shop))->toBe(['DB_CONNECTION' => 'sqlite']);
});

test('keeping the same database and Redis changes nothing on them', function () {
    ['owner' => $owner, 'server' => $server, 'shop' => $shop] = setupFixture();
    $ledger = ManagedDatabase::factory()->for($server)->for($server->team)->postgres()->create(['name' => 'ledger', 'db_user' => 'ledger']);
    $shop->databases()->attach($ledger->getKey());

    $this->actingAs($owner)->put(route('servers.applications.setup.update', [$server, $shop]), setupPayload(['database' => 'existing:' . $ledger->getKey(), 'redis' => 'none']))
        ->assertSessionHasNoErrors()
        ->assertSessionMissing('success');

    expect($shop->fresh()->databases->modelKeys())->toBe([$ledger->getKey()])
        ->and(Activity::query()->whereIn('description', ['database_connected', 'database_disconnected'])->count())->toBe(0);
});

test("a database from another server can't be picked on Edit setup, and nothing changes", function () {
    ['owner' => $owner, 'server' => $server, 'shop' => $shop] = setupFixture();
    $elsewhere = ManagedDatabase::factory()->postgres()->create(['name' => 'elsewhere']);

    $this->actingAs($owner)->put(route('servers.applications.setup.update', [$server, $shop]), setupPayload(['database' => 'existing:' . $elsewhere->getKey(), 'repository_branch' => 'release']))
        ->assertSessionHasErrors(['database' => "That database isn't on web. Pick another one."]);

    expect($shop->fresh()->repository_branch)->toBe('main')
        ->and($shop->fresh()->databases)->toBeEmpty();
});

test('saving the setup changes it for the next deploy and records what changed', function () {
    ['owner' => $owner, 'server' => $server, 'shop' => $shop] = setupFixture();

    $this->actingAs($owner)->put(route('servers.applications.setup.update', [$server, $shop]), setupPayload(['framework' => 'symfony', 'repository_branch' => 'release', 'app_size' => 'medium', 'runtime_version' => '8.4']))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('servers.applications.show', [$server, $shop]))
        ->assertSessionHas('success', 'Saved. Check the repository before the next deploy.');

    $shop->refresh();

    expect([$shop->framework, $shop->repository_branch, $shop->app_size, $shop->runtime_pins])->toBe(['symfony', 'release', 'medium', ['php' => '8.4']])
        ->and(Activity::query()->where('description', ApplicationSetupController::CHANGED)->sole()->properties->all())->toBe(['changed' => ['framework', 'repository_branch', 'app_size', 'runtime_pins']])
        ->and(collect(app(ActivityFeed::class)->forServer($server, viewer: $owner))->map(fn(array $entry): string => collect($entry['parts'])->pluck('text')->implode(''))->implode("\n"))->toContain('Setup changed on shop: framework, branch, size and runtime version');
});

test('a setup change Coresmith could not check holds the next deploy until the repository is checked', function () {
    ['owner' => $owner, 'server' => $server, 'shop' => $shop] = setupFixture(['repository_check_pending' => false, 'repository_checked_at' => now()->subDay()]);

    $this->actingAs($owner)->put(route('servers.applications.setup.update', [$server, $shop]), setupPayload(['repository_branch' => 'release']))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', 'Saved. Check the repository before the next deploy.');

    $shop->refresh();

    expect($shop->needsRepositoryCheck())->toBeTrue()
        ->and($shop->repository_check)->toBe(['state' => 'changed'])
        ->and(app(DeployGuard::class)->refusal($shop))->toBe(DeployGuard::SETUP_CHANGED);
});

test('a new size needs no new check, since the repository has nothing to say about it', function () {
    ['owner' => $owner, 'server' => $server, 'shop' => $shop] = setupFixture(['repository_check_pending' => false]);

    $this->actingAs($owner)->put(route('servers.applications.setup.update', [$server, $shop]), setupPayload(['app_size' => 'medium']))
        ->assertSessionHas('success', 'Saved. The next deploy uses it.');

    expect($shop->fresh()->needsRepositoryCheck())->toBeFalse();
});

test('a setup that matches what Coresmith read is saved as checked', function () {
    ['owner' => $owner, 'server' => $server, 'shop' => $shop] = setupFixture(['repository_check_pending' => true, 'repository_check' => ['state' => 'changed']]);
    $commit = str_repeat('c', 40);
    $id = app(DetectionStore::class)->put($owner, $server, RepositoryAccess::public('https://github.com/acme/shop.git'), 'release', '/', new RepositoryRead(RepositoryFixture::facts('laravel-13'), $commit));

    $this->actingAs($owner)->put(route('servers.applications.setup.update', [$server, $shop]), setupPayload(['repository_branch' => 'release', 'detection' => $id]))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', 'Saved. The next deploy uses it.');

    $shop->refresh();

    expect($shop->needsRepositoryCheck())->toBeFalse()
        ->and($shop->repository_check)->toBe(['state' => 'passed', 'commit' => $commit])
        ->and($shop->repository_checked_at)->not->toBeNull();
});

test('a setup the checks know will fail is refused and nothing changes', function () {
    ['owner' => $owner, 'server' => $server, 'shop' => $shop] = setupFixture();
    $id = app(DetectionStore::class)->put($owner, $server, RepositoryAccess::public('https://github.com/acme/shop.git'), 'main', '/', new RepositoryRead(RepositoryFixture::facts('slim-skeleton')));

    $this->actingAs($owner)->put(route('servers.applications.setup.update', [$server, $shop]), setupPayload(['app_size' => 'medium', 'detection' => $id]))
        ->assertSessionHasErrors(['framework' => "There is no artisan file at the repository's root, so this isn't a Laravel app. Pick Plain PHP, or Symfony if it uses Symfony."]);

    expect($shop->fresh()->app_size)->toBe('small');
});

test('saving the setup unchanged changes nothing and records nothing', function () {
    ['owner' => $owner, 'server' => $server, 'shop' => $shop] = setupFixture();

    $this->actingAs($owner)->put(route('servers.applications.setup.update', [$server, $shop]), setupPayload())
        ->assertSessionHasNoErrors()
        ->assertSessionMissing('success');

    expect(Activity::query()->where('description', ApplicationSetupController::CHANGED)->count())->toBe(0);
});

test('bad values are refused with the same messages as Add an app', function () {
    ['owner' => $owner, 'server' => $server, 'shop' => $shop] = setupFixture();
    $route = route('servers.applications.setup.update', [$server, $shop]);

    $this->actingAs($owner)->put($route, setupPayload(['repository_branch' => 'bad..branch']))
        ->assertSessionHasErrors(['repository_branch' => 'That is not a branch name Git accepts. Use something like main or release/2.0.']);
    $this->actingAs($owner)->put($route, setupPayload(['web_directory' => '']))->assertSessionHasErrors('web_directory');
    $this->actingAs($owner)->put($route, setupPayload(['directory' => '../etc']))
        ->assertSessionHasErrors(['directory' => 'Use a folder inside the repository, like / or apps/web.']);

    expect($shop->fresh()->repository_branch)->toBe('main');
});

test('an app linked to GitHub keeps its repository, which comes from the connection', function () {
    ['team' => $team, 'owner' => $owner, 'server' => $server] = setupFixture();
    $connection = SourceProvider::factory()->create(['team_id' => $team->id, 'type' => SourceProviderType::GitHubApp->value, 'name' => 'acme']);
    $linked = Application::factory()->for($team)->for($server)->create([
        'name' => 'linked',
        'type' => ApplicationType::Php,
        'framework' => 'laravel',
        'repository_url' => 'https://github.com/acme/linked.git',
        'repository_provider' => 'github',
        'source_provider_id' => $connection->id,
        'repository_id' => '42',
        'repository_branch' => 'main',
        'directory' => '/',
        'web_directory' => '/public',
        'app_size' => 'small',
    ]);

    $this->actingAs($owner)->get(route('servers.applications.setup.edit', [$server, $linked]))
        ->assertInertia(fn(AssertableInertia $page) => $page->where('setup.application.source', ['kind' => 'github', 'full_name' => 'acme/linked', 'account' => 'acme', 'connection_id' => $connection->id, 'repository_id' => '42']));

    $this->actingAs($owner)->put(route('servers.applications.setup.update', [$server, $linked]), setupPayload(['repository_url' => 'https://github.com/evil/other.git']))
        ->assertSessionHasErrors(['repository_url' => "This app's repository comes from its GitHub connection, so it can't be changed here."]);

    $this->actingAs($owner)->put(route('servers.applications.setup.update', [$server, $linked]), collect(setupPayload(['repository_branch' => 'develop']))->except('repository_url')->all())
        ->assertSessionHasNoErrors();

    expect($linked->fresh()->only(['repository_url', 'repository_branch']))->toBe(['repository_url' => 'https://github.com/acme/linked.git', 'repository_branch' => 'develop']);
});

test('people who cannot change the app cannot change its setup, and another server is not found', function () {
    ['team' => $team, 'owner' => $owner, 'server' => $server, 'shop' => $shop] = setupFixture();
    $deployer = User::factory()->create(['current_team_id' => $team->id]);
    $team->members()->attach($deployer, ['role' => TeamRole::Member->value, 'sees_everything' => true]);
    $elsewhere = Server::factory()->for($team)->create();

    $this->actingAs($deployer->fresh())->get(route('servers.applications.setup.edit', [$server, $shop]))->assertForbidden();
    $this->actingAs($deployer->fresh())->put(route('servers.applications.setup.update', [$server, $shop]), setupPayload(['repository_branch' => 'x']))->assertForbidden();
    $this->actingAs($owner)->put(route('servers.applications.setup.update', [$elsewhere, $shop]), setupPayload(['repository_branch' => 'x']))->assertNotFound();

    expect($shop->fresh()->repository_branch)->toBe('main');
});

test('the app page sums up the setup with a way to edit it', function () {
    ['owner' => $owner, 'server' => $server, 'shop' => $shop] = setupFixture();

    $this->actingAs($owner)->get(route('servers.applications.show', [$server, $shop]))
        ->assertInertia(fn(AssertableInertia $page) => $page
            ->where('setup.href', route('servers.applications.setup.edit', [$server, $shop]))
            ->where('setup.rows', [
                ['label' => 'Repository', 'value' => 'github.com/acme/shop'],
                ['label' => 'Branch', 'value' => 'main'],
                ['label' => 'Folder', 'value' => '/'],
                ['label' => 'Web root', 'value' => '/public'],
                ['label' => 'Size', 'value' => 'Small'],
            ]));
});
