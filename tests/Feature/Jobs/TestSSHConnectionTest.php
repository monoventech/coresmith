<?php

declare(strict_types=1);

use App\Enums\ServerStatus;
use App\Exceptions\HostKeyChangedException;
use App\Exceptions\SSHCommandFailedException;
use App\Jobs\TestSSHConnection;
use App\Models\Server;
use App\Services\SSH\HostKeyFingerprint;
use App\Services\SSH\SSHConnectionManager;
use Tests\Support\FakeSSHTransport;

function runConnectionTest(Server $server, FakeSSHTransport $transport): void
{
    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $transport);

    try {
        (new TestSSHConnection($server))->handle(app(SSHConnectionManager::class));
    } finally {
        SSHConnectionManager::createTransportUsing(null);
    }
}

test('a successful test records the connection time', function () {
    $server = Server::factory()->create(['host_key_fingerprint' => null, 'last_connected_at' => null]);

    runConnectionTest($server, new FakeSSHTransport);

    expect($server->fresh()->last_connected_at)->not->toBeNull();
});

test('a pending server moves to provisioning', function () {
    $server = Server::factory()->create(['host_key_fingerprint' => null]);

    runConnectionTest($server, new FakeSSHTransport);

    expect($server->fresh()->status)->toBe(ServerStatus::Provisioning);
});

test('it runs whoami and uname', function () {
    $server = Server::factory()->create(['host_key_fingerprint' => null]);
    $transport = new FakeSSHTransport;

    runConnectionTest($server, $transport);

    expect($transport->commands())->toBe(['whoami', 'uname -a']);
});

test('a failing command leaves the server untouched', function () {
    $server = Server::factory()->create(['host_key_fingerprint' => null, 'last_connected_at' => null]);
    $transport = (new FakeSSHTransport)->queueResult('', 1);

    expect(fn() => runConnectionTest($server, $transport))->toThrow(SSHCommandFailedException::class);

    expect($server->fresh()->last_connected_at)->toBeNull()
        ->and($server->fresh()->status)->toBe(ServerStatus::Pending);
});

test('a changed host key does not clear the stored fingerprint', function () {
    $known = HostKeyFingerprint::fromHostKey('original-key');
    $server = Server::factory()->create(['host_key_fingerprint' => $known]);
    $transport = (new FakeSSHTransport)->setHostKey('attacker-key');

    expect(fn() => runConnectionTest($server, $transport))->toThrow(HostKeyChangedException::class);

    expect($server->fresh()->host_key_fingerprint)->toBe($known);
});
