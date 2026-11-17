<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Enums\TeamRole;
use App\Models\Application;
use App\Models\ManagedDatabase;
use App\Models\Server;
use App\Models\User;
use App\Services\Database\DatabaseConnections;
use Inertia\Testing\AssertableInertia;
use Spatie\Activitylog\Models\Activity;

function connectionsServer(): Server
{
    return Server::factory()->create();
}

function connectionsApp(Server $server, array $attributes = []): Application
{
    return Application::factory()->for($server->team)->for($server)->create(['type' => ApplicationType::Php, 'framework' => 'laravel', 'env_variables' => null, ...$attributes]);
}

function connectionsDatabase(Server $server, string $state = 'postgres', array $attributes = []): ManagedDatabase
{
    $factory = ManagedDatabase::factory()->for($server)->for($server->team);

    return ($state === 'mysql' ? $factory : $factory->{$state}())->create(['name' => 'shop', 'db_user' => 'shop', 'db_password' => 'p@ss/word', ...$attributes]);
}

test('a Laravel app gets the DB_ settings of its PostgreSQL or MySQL database, on the real port', function (string $state, string $connection, string $port) {
    $server = connectionsServer();

    expect(app(DatabaseConnections::class)->variables(connectionsApp($server), connectionsDatabase($server, $state)))->toBe([
        'DB_CONNECTION' => $connection,
        'DB_HOST' => '127.0.0.1',
        'DB_PORT' => $port,
        'DB_DATABASE' => 'shop',
        'DB_USERNAME' => 'shop',
        'DB_PASSWORD' => 'p@ss/word',
    ]);
})->with([
    'PostgreSQL' => ['postgres', 'pgsql', '5432'],
    'MySQL' => ['mysql', 'mysql', '3306'],
]);

test("a Laravel app gets its Redis database's own port and password, and the old shared Redis has none", function () {
    $server = connectionsServer();
    $connections = app(DatabaseConnections::class);

    expect($connections->variables(connectionsApp($server), connectionsDatabase($server, 'redis', ['port' => 6383])))->toBe(['REDIS_HOST' => '127.0.0.1', 'REDIS_PORT' => '6383', 'REDIS_PASSWORD' => 'p@ss/word'])
        ->and($connections->variables(connectionsApp($server), connectionsDatabase($server, 'redis', ['name' => 'cache'])))->toBe(['REDIS_HOST' => '127.0.0.1', 'REDIS_PORT' => '6379', 'REDIS_PASSWORD' => 'null']);
});

test('other apps get a URL with the password encoded, unless their variables already use the DB_ names', function () {
    $server = connectionsServer();
    $connections = app(DatabaseConnections::class);
    $database = connectionsDatabase($server);

    expect($connections->variables(connectionsApp($server, ['type' => ApplicationType::Ruby, 'framework' => 'rails']), $database))->toBe(['DATABASE_URL' => 'postgresql://shop:p%40ss%2Fword@127.0.0.1:5432/shop'])
        ->and($connections->variables(connectionsApp($server, ['type' => ApplicationType::Js, 'framework' => null, 'env_variables' => "DB_HOST=db\n"]), $database))->toHaveKey('DB_HOST', '127.0.0.1')
        ->and($connections->variables(connectionsApp($server, ['type' => ApplicationType::Js, 'framework' => null]), connectionsDatabase($server, 'redis', ['name' => 'jobs', 'port' => 6384])))->toBe(['REDIS_URL' => 'redis://:p%40ss%2Fword@127.0.0.1:6384']);
});

test('a Spring Boot app gets the Spring datasource settings', function () {
    $server = connectionsServer();

    expect(app(DatabaseConnections::class)->variables(connectionsApp($server, ['type' => ApplicationType::Java, 'framework' => 'spring-boot']), connectionsDatabase($server)))->toBe([
        'SPRING_DATASOURCE_URL' => 'jdbc:postgresql://127.0.0.1:5432/shop',
        'SPRING_DATASOURCE_USERNAME' => 'shop',
        'SPRING_DATASOURCE_PASSWORD' => 'p@ss/word',
    ]);
});

test('connecting writes the settings over the old ones, keeps the rest, and is recorded', function () {
    $server = connectionsServer();
    $application = connectionsApp($server, ['env_variables' => "APP_NAME=Shop\nDB_HOST=10.0.0.9\nDB_DATABASE=forge\n"]);
    $database = connectionsDatabase($server);

    app(DatabaseConnections::class)->connect($application, $database);

    expect($application->fresh()->databases->modelKeys())->toBe([$database->getKey()])
        ->and($application->fresh()->env_variables)->toBe("APP_NAME=Shop\nDB_HOST=\"127.0.0.1\"\nDB_DATABASE=\"shop\"\nDB_CONNECTION=\"pgsql\"\nDB_PORT=\"5432\"\nDB_USERNAME=\"shop\"\nDB_PASSWORD=\"p@ss/word\"\n")
        ->and(Activity::query()->where('description', 'database_connected')->where('subject_id', $application->getKey())->exists())->toBeTrue();
});

test('a new password reaches every connected app and no other', function () {
    $server = connectionsServer();
    $database = connectionsDatabase($server);
    $connected = connectionsApp($server);
    $other = connectionsApp($server, ['env_variables' => "DB_PASSWORD=untouched\n"]);
    app(DatabaseConnections::class)->connect($connected, $database);

    $database->forceFill(['db_password' => 'rotated'])->save();
    app(DatabaseConnections::class)->refresh($database->fresh());

    expect($connected->fresh()->env_variables)->toContain('DB_PASSWORD="rotated"')
        ->and($other->fresh()->env_variables)->toBe("DB_PASSWORD=untouched\n");
});

test('the database page lists the apps connected to it, linked, and no others', function () {
    $server = connectionsServer();
    $database = connectionsDatabase($server);
    $shop = connectionsApp($server, ['name' => 'shop']);
    $blog = connectionsApp($server, ['name' => 'blog']);
    connectionsApp($server, ['name' => 'other']);
    $shop->databases()->attach($database);
    $blog->databases()->attach($database);
    $owner = User::factory()->create(['current_team_id' => $server->team_id]);
    $server->team->members()->attach($owner, ['role' => TeamRole::Owner->value, 'sees_everything' => true]);

    $this->actingAs($owner->fresh())->get(route('servers.databases.show', [$server, $database]))
        ->assertInertia(fn(AssertableInertia $page) => $page->where('connected', [
            ['name' => 'blog', 'href' => route('servers.applications.show', [$server, $blog])],
            ['name' => 'shop', 'href' => route('servers.applications.show', [$server, $shop])],
        ]));
});

test('the app page links the databases the app is connected to', function () {
    $server = connectionsServer();
    $application = connectionsApp($server);
    $database = connectionsDatabase($server);
    $cache = connectionsDatabase($server, 'redis', ['name' => 'cache']);
    connectionsDatabase($server, 'mysql', ['name' => 'unconnected']);
    $application->databases()->attach([$database->getKey(), $cache->getKey()]);
    $owner = User::factory()->create(['current_team_id' => $server->team_id]);
    $server->team->members()->attach($owner, ['role' => TeamRole::Owner->value, 'sees_everything' => true]);

    $this->actingAs($owner->fresh())->get(route('servers.applications.show', [$server, $application]))
        ->assertInertia(fn(AssertableInertia $page) => $page->where('application.databases', [
            ['name' => 'cache', 'href' => route('servers.databases.show', [$server, $cache])],
            ['name' => 'shop', 'href' => route('servers.databases.show', [$server, $database])],
        ]));
});
