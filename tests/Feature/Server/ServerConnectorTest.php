<?php

declare(strict_types=1);

use App\Enums\ServerStatus;
use App\Exceptions\ServerConnectionFailed;
use App\Jobs\AuditServer;
use App\Models\Server;
use App\Services\Server\ServerConnector;
use App\Services\SSH\SSHConnectionManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\FakeSSHTransport;
use Tests\Support\TraceSecrets;

const CONNECTOR_PASSWORD = 'tr0ub4dor&3-correct';

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

test('root with a password gets the key added directly, then signs in with it', function () {
    $server = connectorPendingServer();
    $transport = (new FakeSSHTransport)->acceptPassword('root', CONNECTOR_PASSWORD);

    connectorUsing($transport, fn(ServerConnector $connector) => $connector->withPassword($server, 'root', CONNECTOR_PASSWORD));

    $install = collect($transport->commands())->first(fn(string $command): bool => str_contains($command, 'authorized_keys'));

    expect($transport->passwordLogins())->toBe(['root'])
        ->and($install)->toStartWith("sh -c 'k=\"" . $server->ssh_public_key . '"')
        ->and($transport->inputFor('authorized_keys'))->toBeNull()
        ->and(array_map(FakeSSHTransport::keyBlob(...), $transport->loginKeys()))->toBe([FakeSSHTransport::keyBlob($server->ssh_public_key)])
        ->and($server->fresh()->ssh_user)->toBe('root')
        ->and($server->fresh()->last_connected_at)->not->toBeNull();

    Queue::assertPushed(AuditServer::class);
});

test('another user adds the key for root through sudo, with the password only on stdin', function () {
    $server = connectorPendingServer(['ssh_user' => 'maya']);
    $transport = (new FakeSSHTransport)->acceptPassword('maya', CONNECTOR_PASSWORD);

    connectorUsing($transport, fn(ServerConnector $connector) => $connector->withPassword($server, 'maya', CONNECTOR_PASSWORD));

    expect($transport->inputFor("sudo -S -p '' sh -c 'k=\"" . $server->ssh_public_key . '"; mkdir -p ~root/.ssh'))->toBe(CONNECTOR_PASSWORD . "\n")
        ->and(implode("\n", $transport->commands()))->not->toContain(CONNECTOR_PASSWORD)
        ->and($server->fresh()->ssh_user)->toBe('root')
        ->and($transport->commands())->toContain('whoami');
});

test('the password is kept nowhere: not on the server row, in the activity log or in a queued job', function () {
    $server = connectorPendingServer(['ssh_user' => 'maya']);

    connectorUsing((new FakeSSHTransport)->acceptPassword('maya', CONNECTOR_PASSWORD), fn(ServerConnector $connector) => $connector->withPassword($server, 'maya', CONNECTOR_PASSWORD));

    expect(json_encode(DB::table('servers')->where('id', $server->id)->first()))->not->toContain(CONNECTOR_PASSWORD)
        ->and(Activity::query()->get()->toJson())->not->toContain(CONNECTOR_PASSWORD);

    Queue::assertPushed(AuditServer::class, fn(AuditServer $job): bool => !str_contains(serialize($job), CONNECTOR_PASSWORD));
});

test('a wrong password says to check it, and nothing on the server changes', function () {
    $server = connectorPendingServer(['ssh_user' => 'maya']);
    $transport = (new FakeSSHTransport)->acceptPassword('maya', 'the real one');

    $message = connectorRefusal($transport, fn(ServerConnector $connector) => $connector->withPassword($server, 'maya', CONNECTOR_PASSWORD));

    expect($message)->toBe('The server refused the password for maya. Check it and try again.')
        ->and($server->fresh()->last_connection_error)->toBe($message)
        ->and($server->fresh()->ssh_user)->toBe('maya')
        ->and($transport->commands())->toBe([]);
});

test('a refused root password points at a sudo user or the command, since root often only takes keys', function () {
    $message = connectorRefusal(new FakeSSHTransport, fn(ServerConnector $connector) => $connector->withPassword(connectorPendingServer(), 'root', CONNECTOR_PASSWORD));

    expect($message)->toBe('The server refused the password for root. Many servers only let root sign in with a key: use your own user and password, or run the command below.');
});

test('a server with password logins turned off says to run the command instead', function () {
    $message = connectorRefusal((new FakeSSHTransport)->refusePasswords(), fn(ServerConnector $connector) => $connector->withPassword(connectorPendingServer(), 'maya', CONNECTOR_PASSWORD));

    expect($message)->toBe("This server doesn't take passwords over SSH. Run the command below on it instead.");
});

test('the server running Coresmith itself is refused before any key is added', function () {
    $server = connectorPendingServer(['ssh_user' => 'maya']);
    $transport = (new FakeSSHTransport)->acceptPassword('maya', CONNECTOR_PASSWORD)->respondTo('ls -d /opt/coresmith', "/opt/coresmith\n", 2);

    $message = connectorRefusal($transport, fn(ServerConnector $connector) => $connector->withPassword($server, 'maya', CONNECTOR_PASSWORD));

    expect($message)->toBe('This server runs Coresmith itself. Add a different server for your apps.')
        ->and(implode("\n", $transport->commands()))->not->toContain('authorized_keys')
        ->and($server->fresh()->ssh_user)->toBe('maya');
});

test('a user who cannot use sudo is told to use one who can', function () {
    $transport = (new FakeSSHTransport)->acceptPassword('maya', CONNECTOR_PASSWORD)->respondTo('sudo -S', "maya is not in the sudoers file.\n", 1);

    $message = connectorRefusal($transport, fn(ServerConnector $connector) => $connector->withPassword(connectorPendingServer(['ssh_user' => 'maya']), 'maya', CONNECTOR_PASSWORD));

    expect($message)->toBe("maya can't use sudo on this server. Use a user who can, or run the command below as root.");
});

test('a key that could not be added says so and offers the command', function () {
    $transport = (new FakeSSHTransport)->acceptPassword('maya', CONNECTOR_PASSWORD)->respondTo('sudo -S', "sh: 1: cannot create authorized_keys: No space left on device\n", 2);

    $message = connectorRefusal($transport, fn(ServerConnector $connector) => $connector->withPassword(connectorPendingServer(['ssh_user' => 'maya']), 'maya', CONNECTOR_PASSWORD));

    expect($message)->toBe("Coresmith signed in as maya but couldn't add its key for root. Run the command below on the server instead.");
});

test('root refusing the new key says how to let it in, and the key stays where it was added', function () {
    $server = connectorPendingServer(['ssh_user' => 'maya']);
    $transport = (new FakeSSHTransport)->acceptPassword('maya', CONNECTOR_PASSWORD)->failLogin();

    $message = connectorRefusal($transport, fn(ServerConnector $connector) => $connector->withPassword($server, 'maya', CONNECTOR_PASSWORD));

    expect($message)->toBe('Coresmith added its key for root, but the server refused it. Let root sign in with a key (PermitRootLogin prohibit-password), then test the connection.')
        ->and($server->fresh()->ssh_user)->toBe('root');
});

test('the password never shows up in the trace of a refusal', function () {
    $transport = (new FakeSSHTransport)->acceptPassword('maya', 'the real one');
    $server = connectorPendingServer(['ssh_user' => 'maya']);

    $error = TraceSecrets::thrownBy(fn() => connectorUsing($transport, fn(ServerConnector $connector) => $connector->withPassword($server, 'maya', CONNECTOR_PASSWORD)));

    expect($error)->toBeInstanceOf(ServerConnectionFailed::class)
        ->and(TraceSecrets::held($error, CONNECTOR_PASSWORD))->toBeFalse();
});
