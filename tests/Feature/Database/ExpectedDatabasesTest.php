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
use App\Services\Database\ExpectedDatabases;
use App\Services\Deployment\EnvironmentFile;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
});

const EXPECTED_FORGE = "DB_CONNECTION=pgsql\nDB_HOST=127.0.0.1\nDB_PORT=5432\nDB_DATABASE=forge\nDB_USERNAME=forge\nDB_PASSWORD=copied\n";

function expectedServer(): Server
{
    return Server::factory()->create(['name' => 'beelink', 'ip_address' => '203.0.113.9', 'status' => ServerStatus::Active]);
}

function expectedApp(Server $server, string $variables = EXPECTED_FORGE, array $attributes = []): Application
{
    return Application::factory()->for($server->team)->for($server)->create(['name' => 'valentine', 'slug' => 'valentine', 'type' => ApplicationType::Php, 'framework' => 'laravel', 'env_variables' => $variables, 'current_release' => null, ...$attributes]);
}

function expectedMember(Server $server, TeamRole $role = TeamRole::Owner): User
{
    $user = User::factory()->create(['current_team_id' => $server->team_id]);
    $server->team->members()->attach($user, ['role' => $role->value, 'sees_everything' => true]);

    return $user->fresh();
}

function expectedBanners(Application $application, ?User $user = null): array
{
    return app(ExpectedDatabases::class)->banners($application, $user ?? expectedMember($application->server));
}

test('variables copied from another server that expect PostgreSQL here, before the first deploy, offer to create one', function () {
    $server = expectedServer();

    expect(expectedBanners(expectedApp($server)))->toBe([[
        'key' => 'postgresql',
        'lead' => "Coresmith hasn't set up PostgreSQL on beelink.",
        'detail' => "valentine's variables expect it at 127.0.0.1:5432, so a deploy can't connect.",
        'field' => 'database',
        'create' => ['value' => 'new:postgresql', 'label' => 'Create a PostgreSQL database for valentine'],
        'existing' => [],
    ]]);
});

test('a database name the server does not have offers the ones it does', function () {
    $server = expectedServer();
    $shop = ManagedDatabase::factory()->postgres()->for($server)->for($server->team)->create(['name' => 'shop']);

    $banner = expectedBanners(expectedApp($server))[0];

    expect($banner['lead'])->toBe('beelink has no PostgreSQL database called forge.')
        ->and($banner['detail'])->toBe("valentine's variables expect it, so a deploy can't connect.")
        ->and($banner['existing'])->toBe([['value' => 'existing:' . $shop->getKey(), 'label' => 'shop']]);
});

test('a port Coresmith does not run the engine on says which one it does', function () {
    $server = expectedServer();
    ManagedDatabase::factory()->postgres()->for($server)->for($server->team)->create(['name' => 'forge']);

    $banner = expectedBanners(expectedApp($server, str_replace('DB_PORT=5432', 'DB_PORT=5433', EXPECTED_FORGE)))[0];

    expect($banner['lead'])->toBe("valentine's variables expect PostgreSQL on port 5433.")
        ->and($banner['detail'])->toBe("Coresmith runs it on 5432 on beelink, so a deploy can't connect.");
});

test('a Redis the app uses but the server does not run offers one, from Laravel settings or a URL', function (string $variables) {
    $banner = expectedBanners(expectedApp(expectedServer(), $variables))[0];

    expect($banner)->toBe([
        'key' => 'redis',
        'lead' => "Coresmith doesn't run a Redis on port 6379 on beelink.",
        'detail' => "valentine's variables expect one there, so a deploy can't connect.",
        'field' => 'redis',
        'create' => ['value' => 'new', 'label' => 'Create a Redis database for valentine'],
        'existing' => [],
    ]);
})->with([
    'Laravel' => ["CACHE_STORE=redis\nREDIS_HOST=127.0.0.1\nREDIS_PORT=6379\n"],
    'a URL' => ["REDIS_URL=redis://localhost:6379/0\n"],
]);

test('a DATABASE_URL on the server itself is read too, including the server address', function () {
    $banner = expectedBanners(expectedApp(expectedServer(), "DATABASE_URL=mysql://shop:secret@203.0.113.9/forge\n", ['type' => ApplicationType::Ruby, 'framework' => 'rails']))[0];

    expect($banner['lead'])->toBe("Coresmith hasn't set up MySQL on beelink.")
        ->and($banner['detail'])->toBe("valentine's variables expect it at 203.0.113.9:3306, so a deploy can't connect.");
});

test('nothing is flagged when the database is elsewhere, already here, connected, or the app has gone live', function (Closure $arrange) {
    $server = expectedServer();
    $application = $arrange($server);

    expect(expectedBanners($application))->toBe([]);
})->with([
    'another host' => [fn(Server $server) => expectedApp($server, str_replace('127.0.0.1', 'db.example.com', EXPECTED_FORGE))],
    'the named database is here' => [function (Server $server) {
        ManagedDatabase::factory()->postgres()->for($server)->for($server->team)->create(['name' => 'forge']);

        return expectedApp($server);
    }],
    'connected through Coresmith' => [function (Server $server) {
        $application = expectedApp($server);
        $application->databases()->attach(ManagedDatabase::factory()->postgres()->for($server)->for($server->team)->create(['name' => 'valentine']));

        return $application;
    }],
    'already live' => [fn(Server $server) => expectedApp($server, attributes: ['current_release' => '20261117-120000'])],
    'Redis named but unused' => [fn(Server $server) => expectedApp($server, "REDIS_HOST=127.0.0.1\nCACHE_STORE=database\n")],
    'a database on the shared Redis' => [function (Server $server) {
        ManagedDatabase::factory()->redis()->for($server)->for($server->team)->create(['name' => 'cache', 'port' => 6379]);

        return expectedApp($server, "CACHE_STORE=redis\nREDIS_PORT=6379\n");
    }],
    'the shared Redis Coresmith installed' => [function (Server $server) {
        $server->forceFill(['meta' => ['installed_databases' => ['redis']]])->save();

        return expectedApp($server, "CACHE_STORE=redis\nREDIS_PORT=6379\n");
    }],
]);

test('a deployer is told about it but offered nothing to change', function () {
    $server = expectedServer();

    $banner = expectedBanners(expectedApp($server), expectedMember($server, TeamRole::Member))[0];

    expect($banner['create'])->toBeNull()
        ->and($banner['existing'])->toBe([]);
});

test('creating a database from the banner connects it, fills in the variables and clears the banner', function () {
    $server = expectedServer();
    $application = expectedApp($server);
    $owner = expectedMember($server);

    $this->actingAs($owner)
        ->post(route('servers.applications.databases.store', [$server, $application]), ['database' => 'new:postgresql'])
        ->assertSessionHas('success', "Creating a PostgreSQL database for valentine. Its settings are in the variables, so deploy once it's ready.");

    $database = ManagedDatabase::query()->sole();
    $variables = app(EnvironmentFile::class)->parse((string) $application->fresh()->env_variables);

    expect($database->type)->toBe(DatabaseType::Postgresql)
        ->and($database->name)->toBe('valentine')
        ->and($variables)->toMatchArray(['DB_DATABASE' => 'valentine', 'DB_USERNAME' => 'valentine', 'DB_PORT' => '5432'])
        ->and(expectedBanners($application->fresh(), $owner))->toBe([]);
    Queue::assertPushed(CreateDatabase::class);
});

test('using a database already on the server connects it as it is', function () {
    $server = expectedServer();
    $application = expectedApp($server);
    $shop = ManagedDatabase::factory()->postgres()->for($server)->for($server->team)->create(['name' => 'shop', 'db_user' => 'shop_app']);

    $this->actingAs(expectedMember($server))
        ->post(route('servers.applications.databases.store', [$server, $application]), ['database' => 'existing:' . $shop->getKey()])
        ->assertSessionHas('success', 'Connected valentine to shop. Its settings are in the variables, so deploy to use it.');

    expect($application->fresh()->databases->modelKeys())->toBe([$shop->getKey()])
        ->and(ManagedDatabase::query()->count())->toBe(1);
});

test('the banner action is refused to deployers, for other servers and for choices that are not a database', function () {
    $server = expectedServer();
    $application = expectedApp($server);
    $elsewhere = ManagedDatabase::factory()->postgres()->create(['name' => 'elsewhere']);
    $owner = expectedMember($server);
    $href = route('servers.applications.databases.store', [$server, $application]);

    $this->actingAs(expectedMember($server, TeamRole::Member))->post($href, ['database' => 'new:postgresql'])->assertForbidden();
    $this->actingAs($owner)->post($href, ['database' => 'sqlite'])->assertSessionHasErrors('database');
    $this->actingAs($owner)->post($href, [])->assertSessionHasErrors(['database', 'redis']);
    $this->actingAs($owner)->post($href, ['database' => 'existing:' . $elsewhere->getKey()])
        ->assertSessionHas('error', "That database isn't on beelink. Pick another one.");

    expect($application->fresh()->databases)->toHaveCount(0);
});
