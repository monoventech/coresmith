<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Models\ManagedDatabase;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\SSH\SSHConnectionManager;
use Illuminate\Support\Sleep;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\FakeSSHTransport;

function sharedRedisServer(array $meta = ['installed_databases' => ['redis']]): Server
{
    $team = Team::factory()->create();

    return Server::factory()->for($team)->create(['name' => 'forge-07', 'host_key_fingerprint' => null, 'os_name' => 'ubuntu', 'meta' => $meta]);
}

function sharedRedisDatabase(Server $server, int $port = 6379): ManagedDatabase
{
    return ManagedDatabase::factory()->redis()->for($server)->for($server->team)->create(['name' => 'cache', 'port' => $port, 'db_password' => 'UnusedSharedPassword0']);
}

function sharedRedisUser(Team $team, TeamRole $role = TeamRole::Admin): User
{
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => $role->value]);

    return $user->fresh();
}

function sharedRedisTransport(FakeSSHTransport $transport): FakeSSHTransport
{
    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $transport);

    return $transport;
}

beforeEach(function () {
    Sleep::fake();
});

afterEach(function () {
    SSHConnectionManager::createTransportUsing(null);
});

test('a database on the shared Redis moves to a Redis of its own, whose password is shown once', function () {
    $transport = sharedRedisTransport((new FakeSSHTransport)->respondTo('redis-cli -p 6380 ping', "PONG\n"));
    $server = sharedRedisServer();
    $database = sharedRedisDatabase($server);
    $admin = sharedRedisUser($server->team);

    $this->actingAs($admin)
        ->post(route('servers.databases.own-redis', [$server, $database]))
        ->assertSessionHas('success', 'Moved cache to a Redis of its own on port 6380. Give the apps that use it the new port and password, then deploy.');

    $fresh = $database->fresh();

    expect($fresh->port)->toBe(6380)
        ->and($fresh->onSharedRedis())->toBeFalse()
        ->and($fresh->db_password)->not->toBe('UnusedSharedPassword0')->toHaveLength(32)
        ->and($fresh->credentials_shown_at)->not->toBeNull()
        ->and(session('databasePassword'))->toBe($fresh->db_password)
        ->and($transport->commands())->toContain('systemctl daemon-reload && systemctl enable coresmith-redis-6380.service && systemctl restart coresmith-redis-6380.service')
        ->and(Activity::query()->where('description', 'database_moved_off_shared_redis')->sole()->subject_id)->toBe($database->id);
});

test('a move skips a port another program listens on', function () {
    sharedRedisTransport((new FakeSSHTransport)
        ->respondTo('ss -Hltn', "LISTEN 0 511 127.0.0.1:6380 0.0.0.0:*\n")
        ->respondTo('redis-cli -p 6381 ping', "PONG\n"));
    $server = sharedRedisServer();
    $database = sharedRedisDatabase($server);

    $this->actingAs(sharedRedisUser($server->team))->post(route('servers.databases.own-redis', [$server, $database]));

    expect($database->fresh()->port)->toBe(6381);
});

test('a move whose instance never answers leaves the database on the shared Redis, and says so', function () {
    sharedRedisTransport((new FakeSSHTransport)->respondTo('redis-cli -p 6380 ping', "Could not connect to Redis at 127.0.0.1:6380: Connection refused\n"));
    $server = sharedRedisServer();
    $database = sharedRedisDatabase($server);

    $this->actingAs(sharedRedisUser($server->team))
        ->post(route('servers.databases.own-redis', [$server, $database]))
        ->assertSessionHas('error', "Coresmith couldn't start a Redis of its own for cache on forge-07, so it's still on the shared Redis. Check the server is online, then try again.")
        ->assertSessionMissing('databasePassword');

    expect($database->fresh()->port)->toBe(6379)
        ->and($database->fresh()->db_password)->toBe('UnusedSharedPassword0');
});

test('only a database still on the shared Redis can move, and only for people who may see its password', function () {
    $transport = sharedRedisTransport(new FakeSSHTransport);
    $server = sharedRedisServer();
    $shared = sharedRedisDatabase($server);
    $instance = ManagedDatabase::factory()->redis()->for($server)->for($server->team)->create(['name' => 'sessions', 'port' => 6380]);

    $this->actingAs(sharedRedisUser($server->team))->post(route('servers.databases.own-redis', [$server, $instance]))->assertNotFound();
    $this->actingAs(sharedRedisUser($server->team, TeamRole::Member))->post(route('servers.databases.own-redis', [$server, $shared]))->assertForbidden();

    expect($transport->calls())->toBe([]);
});

test('the Databases tab says how many databases still use the shared Redis and whether it can be turned off', function (array $meta, array $ports, ?array $expected) {
    $server = sharedRedisServer($meta);

    foreach ($ports as $port) {
        ManagedDatabase::factory()->redis()->for($server)->for($server->team)->create(['port' => $port]);
    }

    $this->actingAs(sharedRedisUser($server->team))
        ->get(route('servers.databases.index', $server))
        ->assertInertia(fn($page) => $page->where('shared_redis', $expected));
})->with([
    'still used' => [['installed_databases' => ['redis']], [6379, 6379, 6380], ['databases' => 2, 'can_turn_off' => false]],
    'unused' => [['installed_databases' => ['redis']], [6380], ['databases' => 0, 'can_turn_off' => true]],
    'turned off' => [['installed_databases' => ['redis'], 'shared_redis' => 'off'], [6380], null],
    'never installed by Coresmith' => [[], [6380], null],
    'someone else\'s Redis still used' => [[], [6379], ['databases' => 1, 'can_turn_off' => false]],
]);

test('the shared Redis is turned off once nothing connects to it', function () {
    $transport = sharedRedisTransport((new FakeSSHTransport)->respondTo('redis-cli -p 6379 CLIENT LIST', "id=7 addr=127.0.0.1:50122 laddr=127.0.0.1:6379 fd=8 name= age=0 idle=0 flags=N db=0 cmd=client|list\n"));
    $server = sharedRedisServer();
    $admin = sharedRedisUser($server->team);

    $this->actingAs($admin)
        ->post(route('servers.shared-redis.off', $server))
        ->assertSessionHas('success', 'Shared Redis turned off. Nothing on forge-07 answers without a password now.');

    expect($transport->commands())->toContain('systemctl disable --now redis-server')
        ->and($server->fresh()->meta['shared_redis'] ?? null)->toBe('off')
        ->and(Activity::query()->where('description', 'shared_redis_turned_off')->count())->toBe(1);
});

test('the shared Redis stays on while apps still connect to it, and the page says how many', function () {
    $transport = sharedRedisTransport((new FakeSSHTransport)->respondTo('redis-cli -p 6379 CLIENT LIST', implode("\n", [
        'id=3 addr=127.0.0.1:41022 laddr=127.0.0.1:6379 fd=8 name= age=9000 idle=2 flags=N db=0 cmd=blpop',
        'id=4 addr=127.0.0.1:41030 laddr=127.0.0.1:6379 fd=9 name= age=9000 idle=1 flags=N db=0 cmd=get',
        'id=9 addr=127.0.0.1:50122 laddr=127.0.0.1:6379 fd=10 name= age=0 idle=0 flags=N db=0 cmd=client|list',
    ]) . "\n"));
    $server = sharedRedisServer();

    $this->actingAs(sharedRedisUser($server->team))
        ->post(route('servers.shared-redis.off', $server))
        ->assertSessionHas('error', '2 connections are still open to the shared Redis. Point the apps that use it at a Redis of their own and deploy, then try again.');

    expect(implode("\n", $transport->commands()))->not->toContain('systemctl disable')
        ->and($server->fresh()->meta['shared_redis'] ?? null)->toBeNull();
});

test('the shared Redis is only turned off when Coresmith installed it and no database uses it', function (array $meta, array $ports) {
    $transport = sharedRedisTransport(new FakeSSHTransport);
    $server = sharedRedisServer($meta);

    foreach ($ports as $port) {
        ManagedDatabase::factory()->redis()->for($server)->for($server->team)->create(['port' => $port]);
    }

    $this->actingAs(sharedRedisUser($server->team))->post(route('servers.shared-redis.off', $server))->assertNotFound();

    expect($transport->calls())->toBe([]);
})->with([
    'still used' => [['installed_databases' => ['redis']], [6379]],
    'not Coresmith\'s' => [[], []],
    'already off' => [['installed_databases' => ['redis'], 'shared_redis' => 'off'], []],
]);

test('a deployer cannot turn off the shared Redis', function () {
    $transport = sharedRedisTransport(new FakeSSHTransport);
    $server = sharedRedisServer();

    $this->actingAs(sharedRedisUser($server->team, TeamRole::Member))->post(route('servers.shared-redis.off', $server))->assertForbidden();

    expect($transport->calls())->toBe([]);
});
