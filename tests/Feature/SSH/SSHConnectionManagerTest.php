<?php

declare(strict_types=1);

use App\Models\Server;
use App\Services\SSH\SSHConnectionManager;
use Tests\Support\FakeSSHTransport;

beforeEach(function () {
    SSHConnectionManager::createTransportUsing(fn (): FakeSSHTransport => new FakeSSHTransport);
});

afterEach(function () {
    SSHConnectionManager::createTransportUsing(null);
});

test('the same server reuses one connection', function () {
    $server = Server::factory()->create(['host_key_fingerprint' => null]);
    $manager = app(SSHConnectionManager::class);

    expect($manager->for($server))->toBe($manager->for($server));
});

test('different servers get different connections', function () {
    $manager = app(SSHConnectionManager::class);
    $a = Server::factory()->create(['host_key_fingerprint' => null]);
    $b = Server::factory()->create(['host_key_fingerprint' => null]);

    expect($manager->for($a))->not->toBe($manager->for($b));
});

test('the manager is a singleton', function () {
    expect(app(SSHConnectionManager::class))->toBe(app(SSHConnectionManager::class));
});

test('disconnecting drops the pooled connection', function () {
    $server = Server::factory()->create(['host_key_fingerprint' => null]);
    $manager = app(SSHConnectionManager::class);
    $first = $manager->for($server);

    $manager->disconnect($server);

    expect($manager->for($server))->not->toBe($first);
});

test('disconnectAll empties the pool', function () {
    $server = Server::factory()->create(['host_key_fingerprint' => null]);
    $manager = app(SSHConnectionManager::class);
    $first = $manager->for($server);

    $manager->disconnectAll();

    expect($manager->for($server))->not->toBe($first);
});

test('twenty commands share a single connection', function () {
    $server = Server::factory()->create(['host_key_fingerprint' => null]);
    $manager = app(SSHConnectionManager::class);

    for ($i = 0; $i < 20; $i++) {
        $manager->for($server)->execute("echo {$i}");
    }

    expect($manager->for($server))->toBe($manager->for($server));
});
