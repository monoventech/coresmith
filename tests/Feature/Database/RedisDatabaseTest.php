<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Jobs\CreateDatabase;
use App\Models\ManagedDatabase;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\Database\DatabaseManager;
use App\Services\Database\RedisPorts;
use App\Services\SSH\SSHConnection;
use App\Services\SSH\SSHConnectionManager;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Tests\Support\FakeSSHTransport;

function redisDatabaseServer(array $attributes = []): Server
{
    return Server::factory()->create(['host_key_fingerprint' => null, 'os_name' => 'ubuntu', ...$attributes]);
}

function redisDatabaseOn(Server $server, int $port, array $attributes = []): ManagedDatabase
{
    return ManagedDatabase::factory()->redis()->for($server)->for($server->team)->create(['port' => $port, ...$attributes]);
}

function redisDatabaseAdmin(Team $team): User
{
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Admin->value]);

    return $user->fresh();
}

afterEach(function () {
    SSHConnectionManager::createTransportUsing(null);
});

test('the first Redis database on a server gets port 6380, and later ones the lowest port still free', function () {
    $server = redisDatabaseServer();

    expect(app(RedisPorts::class)->next($server))->toBe(6380);

    redisDatabaseOn($server, 6380);
    redisDatabaseOn($server, 6382);
    redisDatabaseOn($server, 6379);
    redisDatabaseOn(redisDatabaseServer(), 6381);

    expect(app(RedisPorts::class)->next($server))->toBe(6381)
        ->and(app(RedisPorts::class)->next($server, [6381]))->toBe(6383);
});

test('a deleted Redis database gives its port back', function () {
    $server = redisDatabaseServer();
    redisDatabaseOn($server, 6380)->delete();

    expect(app(RedisPorts::class)->next($server))->toBe(6380);
});

test('a server with every Redis port taken says so', function () {
    $server = redisDatabaseServer(['name' => 'forge-09']);

    foreach (range(6380, 6479) as $port) {
        redisDatabaseOn($server, $port);
    }

    expect(fn() => app(RedisPorts::class)->next($server))
        ->toThrow(RuntimeException::class, 'forge-09 has no Redis port left between 6380 and 6479. Delete a Redis database you no longer need, then try again.');
});

test('a Redis database is created with just a name, and gets a port of its own', function () {
    Queue::fake();
    $server = redisDatabaseServer();
    $admin = redisDatabaseAdmin($server->team);

    $this->actingAs($admin)->post(route('servers.databases.store', $server), ['type' => 'redis', 'name' => 'cache'])->assertSessionHasNoErrors();
    $this->actingAs($admin)->post(route('servers.databases.store', $server), ['type' => 'redis', 'name' => 'sessions'])->assertSessionHasNoErrors();

    expect(ManagedDatabase::query()->where('name', 'cache')->sole())
        ->port->toBe(6380)
        ->db_user->toBeNull()
        ->and(ManagedDatabase::query()->where('name', 'sessions')->sole()->port)->toBe(6381);

    Queue::assertPushed(CreateDatabase::class, 2);
});

test('a MySQL database still needs its user and privileges', function () {
    Queue::fake();
    $server = redisDatabaseServer();

    $this->actingAs(redisDatabaseAdmin($server->team))
        ->post(route('servers.databases.store', $server), ['type' => 'mysql', 'name' => 'shop'])
        ->assertSessionHasErrors(['db_user', 'privilege_level']);
});

test('creating a Redis database starts an instance of its own and keeps its password sealed', function () {
    Sleep::fake();
    $server = redisDatabaseServer();
    $database = redisDatabaseOn($server, 6380, ['db_password' => null]);
    $transport = (new FakeSSHTransport)->respondTo('redis-cli -p 6380 ping', "PONG\n");
    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $transport);

    app()->call([new CreateDatabase($database), 'handle']);

    $fresh = $database->fresh();

    expect($transport->commands())->toContain('systemctl daemon-reload && systemctl enable coresmith-redis-6380.service && systemctl restart coresmith-redis-6380.service')
        ->and($fresh->db_password)->toHaveLength(32)
        ->and($fresh->is_external)->toBeFalse()
        ->and($fresh->hasPassword())->toBeTrue()
        ->and(array_column($transport->sent(), 1))->toContain($fresh->db_password)
        ->and(implode("\n", $transport->commands()))->not->toContain($fresh->db_password);
});

test('a port another program took meanwhile is skipped, and the database moves to the next free one', function () {
    Sleep::fake();
    $server = redisDatabaseServer();
    $database = redisDatabaseOn($server, 6380, ['db_password' => null]);
    $transport = (new FakeSSHTransport)
        ->respondTo('ss -Hltn', "LISTEN 0 511 127.0.0.1:6380 0.0.0.0:*\nLISTEN 0 4096 0.0.0.0:22 0.0.0.0:*\n")
        ->respondTo('systemctl is-active --quiet coresmith-redis-6380.service', '', 3)
        ->respondTo('redis-cli -p 6381 ping', "PONG\n");
    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $transport);

    app()->call([new CreateDatabase($database), 'handle']);

    expect($database->fresh()->port)->toBe(6381)
        ->and(implode("\n", $transport->commands()))->toContain('systemctl restart coresmith-redis-6381.service')
        ->not->toContain('coresmith-redis-6380.service && systemctl restart');
});

test('a port its own instance already holds is kept, so a retried creation does not leave an instance behind', function () {
    Sleep::fake();
    $server = redisDatabaseServer();
    $database = redisDatabaseOn($server, 6380, ['db_password' => null]);
    $transport = (new FakeSSHTransport)
        ->respondTo('ss -Hltn', "LISTEN 0 511 127.0.0.1:6380 0.0.0.0:*\n")
        ->respondTo('systemctl is-active --quiet coresmith-redis-6380.service', '', 0)
        ->respondTo('redis-cli -p 6380 ping', "PONG\n");
    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $transport);

    app()->call([new CreateDatabase($database), 'handle']);

    expect($database->fresh()->port)->toBe(6380);
});

test('rotating or dropping a Redis database works on its own instance, and a shared one has nothing to drop', function () {
    Sleep::fake();
    $server = redisDatabaseServer();
    $instance = redisDatabaseOn($server, 6381);
    $shared = redisDatabaseOn($server, 6379);
    $transport = (new FakeSSHTransport)->respondTo('redis-cli -p 6381 ping', "PONG\n");
    $connection = (new SSHConnection($server, $transport))->connect();
    $databases = app(DatabaseManager::class);

    $databases->changePassword($connection, $instance, 'N3wR3disPassw0rd');
    $databases->drop($connection, $instance);
    $databases->drop($connection, $shared);

    expect($transport->commands())->toContain('systemctl restart coresmith-redis-6381.service')
        ->toContain('systemctl disable --now coresmith-redis-6381.service || true')
        ->and(implode("\n", $transport->commands()))->not->toContain('6379');
});

test('a Redis database on the shared instance still has no password to change', function () {
    $server = redisDatabaseServer();
    $shared = redisDatabaseOn($server, 6379);

    app(DatabaseManager::class)->changePassword((new SSHConnection($server, new FakeSSHTransport))->connect(), $shared, 'N3wR3disPassw0rd');
})->throws(LogicException::class);
