<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Enums\DatabaseType;
use App\Enums\ServerStatus;
use App\Enums\TeamRole;
use App\Jobs\CreateDatabase;
use App\Models\Application;
use App\Models\ManagedDatabase;
use App\Models\Server;
use App\Models\User;
use App\Services\Database\AppDatabaseChoice;
use App\Services\Deployment\EnvironmentFile;
use App\Services\Deployment\ReleasePaths;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    Queue::fake();
});

function choiceServer(ServerStatus $status = ServerStatus::Active): Server
{
    return Server::factory()->create(['status' => $status]);
}

function choiceOwner(Server $server): User
{
    $user = User::factory()->create(['current_team_id' => $server->team_id]);
    $server->team->members()->attach($user, ['role' => TeamRole::Owner->value, 'sees_everything' => true]);

    return $user->fresh();
}

function choiceAdd(Server $server, array $overrides = []): TestResponse
{
    return test()->actingAs(choiceOwner($server))->post(route('servers.applications.store', $server), [
        'name' => 'Shop',
        'type' => ApplicationType::Php->value,
        'framework' => 'laravel',
        'repository_branch' => 'main',
        'directory' => '/',
        'web_directory' => '/public',
        'app_size' => 'small',
        ...$overrides,
    ]);
}

function choiceVariables(Application $application): array
{
    return app(EnvironmentFile::class)->parse((string) $application->env_variables);
}

test('a new PostgreSQL database is created for the app, named after it, and its settings are written before the first deploy', function () {
    $server = choiceServer();

    choiceAdd($server, ['database' => 'new:postgresql'])->assertSessionHasNoErrors();

    $application = Application::query()->sole();
    $database = ManagedDatabase::query()->sole();

    expect($database->type)->toBe(DatabaseType::Postgresql)
        ->and($database->name)->toBe('shop')
        ->and($database->port)->toBe(5432)
        ->and($application->databases->modelKeys())->toBe([$database->getKey()])
        ->and(choiceVariables($application))->toMatchArray(['DB_CONNECTION' => 'pgsql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '5432', 'DB_DATABASE' => 'shop', 'DB_USERNAME' => 'shop']);
    Queue::assertPushed(CreateDatabase::class, fn(CreateDatabase $job): bool => $job->database->is($database));
});

test('a new Redis database gets its own port, and an existing database on the server is connected as it is', function () {
    $server = choiceServer();
    $existing = ManagedDatabase::factory()->for($server)->for($server->team)->create(['name' => 'ledger', 'db_user' => 'ledger']);

    choiceAdd($server, ['database' => 'existing:' . $existing->getKey(), 'redis' => 'new'])->assertSessionHasNoErrors();

    $application = Application::query()->sole();
    $redis = ManagedDatabase::query()->where('type', DatabaseType::Redis->value)->sole();

    expect($redis->port)->toBeGreaterThanOrEqual(6380)
        ->and($application->databases->modelKeys())->toEqualCanonicalizing([$existing->getKey(), $redis->getKey()])
        ->and(choiceVariables($application))->toMatchArray(['DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'ledger', 'REDIS_PORT' => (string) $redis->port]);
});

test('SQLite sets a Laravel app to SQLite in the database file deploys keep, and creates nothing', function () {
    $server = choiceServer();

    choiceAdd($server, ['database' => 'sqlite'])->assertSessionHasNoErrors();

    $application = Application::query()->sole();

    expect(choiceVariables($application))->toMatchArray(['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => (new ReleasePaths($application))->shared() . '/database/database.sqlite'])
        ->and(ManagedDatabase::query()->count())->toBe(0);
});

test("a server that isn't ready yet can't get a new database, and a database from another server can't be picked", function () {
    $pending = choiceServer(ServerStatus::Pending);
    $other = ManagedDatabase::factory()->create();

    choiceAdd($pending, ['database' => 'new:mysql'])
        ->assertSessionHasErrors(['database' => "Coresmith can create a database once {$pending->name} is ready. Pick another option for now."]);

    $server = choiceServer();
    choiceAdd($server, ['database' => 'existing:' . $other->getKey()])
        ->assertSessionHasErrors(['database' => "That database isn't on {$server->name}. Pick another one."]);

    expect(Application::query()->count())->toBe(0);
});

test("Add an app offers the server's own databases by name, and new ones once the server is ready", function () {
    $server = choiceServer();
    $ledger = ManagedDatabase::factory()->for($server)->for($server->team)->create(['name' => 'ledger']);
    $cache = ManagedDatabase::factory()->redis()->for($server)->for($server->team)->create(['name' => 'cache']);
    ManagedDatabase::factory()->create(['name' => 'elsewhere']);

    $this->actingAs(choiceOwner($server))->get(route('servers.applications.create', $server))
        ->assertInertia(fn(AssertableInertia $page) => $page
            ->where('databases.choices', [
                ['value' => 'existing:' . $cache->getKey(), 'name' => 'cache', 'type' => 'redis'],
                ['value' => 'existing:' . $ledger->getKey(), 'name' => 'ledger', 'type' => 'mysql'],
            ])
            ->where('databases.can_create', true));

    $pending = choiceServer(ServerStatus::Pending);

    $this->actingAs(choiceOwner($pending))->get(route('servers.applications.create', $pending))
        ->assertInertia(fn(AssertableInertia $page) => $page->where('databases.choices', [])->where('databases.can_create', false));
});

test('an existing database is only connected by someone who may see its password, since it lands in the variables', function () {
    $server = choiceServer();
    $ledger = ManagedDatabase::factory()->for($server)->for($server->team)->create(['name' => 'ledger']);
    $deployer = User::factory()->create();
    $server->team->members()->attach($deployer, ['role' => TeamRole::Member->value, 'sees_everything' => true]);
    $choice = app(AppDatabaseChoice::class);

    expect($choice->problems($server, $deployer->fresh(), 'existing:' . $ledger->getKey(), null))->toBe(['database' => "That database isn't on {$server->name}. Pick another one."])
        ->and($choice->problems($server, choiceOwner($server), 'existing:' . $ledger->getKey(), null))->toBe([]);
});
