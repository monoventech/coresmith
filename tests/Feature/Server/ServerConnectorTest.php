<?php

declare(strict_types=1);

use App\Enums\ServerStatus;
use App\Exceptions\ServerConnectionFailed;
use App\Jobs\AuditServer;
use App\Models\Server;
use App\Services\Server\ServerConnector;
use App\Services\SSH\SSHConnectionManager;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeSSHTransport;

function connectorUsing(FakeSSHTransport $transport, Closure $connect): void
{
    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $transport);

    try {
        $connect(app(ServerConnector::class));
    } finally {
        SSHConnectionManager::createTransportUsing(null);
    }
}

function connectorRefusal(FakeSSHTransport $transport, Closure $connect): string
{
    try {
        connectorUsing($transport, $connect);
    } catch (ServerConnectionFailed $failed) {
        return $failed->getMessage();
    }

    test()->fail('Expected the connection to be refused.');
}

function connectorPendingServer(array $attributes = []): Server
{
    return Server::factory()->create(['host_key_fingerprint' => null, 'status' => ServerStatus::Pending, 'last_connected_at' => null, ...$attributes]);
}

beforeEach(fn() => Queue::fake());

test('a working key records the connection and starts the check that comes before provisioning', function () {
    $server = connectorPendingServer(['last_connection_error' => 'An older failure.']);

    connectorUsing(new FakeSSHTransport, fn(ServerConnector $connector) => $connector->withKey($server));

    $server->refresh();

    expect($server->last_connected_at)->not->toBeNull()
        ->and($server->last_connection_error)->toBeNull()
        ->and($server->status)->toBe(ServerStatus::Pending)
        ->and($server->audit['state'])->toBe('running');

    Queue::assertPushed(AuditServer::class);
});

test('testing the key runs whoami and uname and nothing else', function () {
    $transport = new FakeSSHTransport;

    connectorUsing($transport, fn(ServerConnector $connector) => $connector->withKey(connectorPendingServer()));

    expect($transport->commands())->toBe(['whoami', 'uname -a']);
});

test('a server past provisioning is only tested, never checked again', function () {
    $server = connectorPendingServer(['status' => ServerStatus::Failed]);

    connectorUsing(new FakeSSHTransport, fn(ServerConnector $connector) => $connector->withKey($server));

    expect($server->fresh()->last_connected_at)->not->toBeNull();

    Queue::assertNotPushed(AuditServer::class);
});

test('a refused key says how to install it and is kept for the connect card', function () {
    $server = connectorPendingServer();

    $message = connectorRefusal((new FakeSSHTransport)->failLogin(), fn(ServerConnector $connector) => $connector->withKey($server));

    expect($message)->toBe("The server refused Coresmith's key for root. Run the command below on the server, then test the connection again.")
        ->and($server->fresh()->last_connection_error)->toBe($message)
        ->and($server->fresh()->last_connected_at)->toBeNull();

    Queue::assertNotPushed(AuditServer::class);
});

test('a server that cannot be reached says which port and what to check', function () {
    $server = connectorPendingServer(['ssh_port' => 2222]);

    $message = connectorRefusal((new FakeSSHTransport)->refuseConnections(), fn(ServerConnector $connector) => $connector->withKey($server));

    expect($message)->toBe('The connection to port 2222 was refused or timed out. Check the server is running and its firewall allows SSH from Coresmith.');
});

test('a test command that fails leaves the server unconnected', function () {
    $server = connectorPendingServer();

    $message = connectorRefusal((new FakeSSHTransport)->queueResult('', 1), fn(ServerConnector $connector) => $connector->withKey($server));

    expect($message)->toBe('Coresmith signed in, but a test command failed with exit code 1. Try again, and check the server if it keeps failing.')
        ->and($server->fresh()->last_connected_at)->toBeNull()
        ->and($server->fresh()->status)->toBe(ServerStatus::Pending);
});

test('a changed host key is never overwritten by a connection test', function () {
    $known = FakeSSHTransport::fingerprintOf('original-key');
    $server = connectorPendingServer(['host_key_fingerprint' => $known]);

    $message = connectorRefusal((new FakeSSHTransport)->setHostKey(FakeSSHTransport::keyNamed('attacker-key')), fn(ServerConnector $connector) => $connector->withKey($server));

    expect($message)->toBe("The server's host key changed since Coresmith first saw it. Review the change before connecting again.")
        ->and($server->fresh()->host_key_fingerprint)->toBe($known);
});

test('anything that is not about the connection is not dressed up as one', function () {
    $server = connectorPendingServer();
    $transport = (new FakeSSHTransport)->onCommand('whoami', fn() => throw new LogicException('A bug, not the network.'));

    expect(fn() => connectorUsing($transport, fn(ServerConnector $connector) => $connector->withKey($server)))
        ->toThrow(LogicException::class, 'A bug, not the network.');
});
