<?php

declare(strict_types=1);

use App\Exceptions\HostKeyChangedException;
use App\Exceptions\SSHAuthenticationException;
use App\Models\Server;
use App\Services\SSH\HostKeyFingerprint;
use App\Services\SSH\SSHConnection;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\FakeSSHTransport;

function connectTo(Server $server, FakeSSHTransport $transport): SSHConnection
{
    return (new SSHConnection($server, $transport))->connect();
}

test('the first connection records the host key fingerprint', function () {
    $server = Server::factory()->create(['host_key_fingerprint' => null]);
    $transport = (new FakeSSHTransport)->setHostKey('host-key-material');

    connectTo($server, $transport);

    expect($server->fresh()->host_key_fingerprint)
        ->toBe(HostKeyFingerprint::fromHostKey('host-key-material'));
});

test('a matching host key connects', function () {
    $transport = (new FakeSSHTransport)->setHostKey('host-key-material');
    $server = Server::factory()->create([
        'host_key_fingerprint' => HostKeyFingerprint::fromHostKey('host-key-material'),
    ]);

    expect(connectTo($server, $transport)->execute('whoami')->successful())->toBeTrue();
});

test('a changed host key is refused before authenticating', function () {
    $transport = (new FakeSSHTransport)->setHostKey('attacker-key');
    $server = Server::factory()->create([
        'host_key_fingerprint' => HostKeyFingerprint::fromHostKey('original-key'),
    ]);

    try {
        connectTo($server, $transport);
        $this->fail('Expected HostKeyChangedException');
    } catch (HostKeyChangedException $e) {
        expect($transport->loginAttempted())->toBeFalse()
            ->and($e->expected())->toBe(HostKeyFingerprint::fromHostKey('original-key'))
            ->and($e->actual())->toBe(HostKeyFingerprint::fromHostKey('attacker-key'));
    }
});

test('a changed host key is logged as a security event', function () {
    $transport = (new FakeSSHTransport)->setHostKey('attacker-key');
    $server = Server::factory()->create([
        'host_key_fingerprint' => HostKeyFingerprint::fromHostKey('original-key'),
    ]);

    try {
        connectTo($server, $transport);
    } catch (HostKeyChangedException) {
        // asserted below
    }

    expect(Activity::where('description', 'ssh_host_key_changed')->count())->toBe(1);
});

test('a failed login throws', function () {
    $server = Server::factory()->create(['host_key_fingerprint' => null]);

    connectTo($server, (new FakeSSHTransport)->failLogin());
})->throws(SSHAuthenticationException::class);

test('sudo is prefixed for a non-root user', function () {
    $server = Server::factory()->create(['ssh_user' => 'coresmith', 'host_key_fingerprint' => null]);
    $transport = new FakeSSHTransport;

    connectTo($server, $transport)->execute('systemctl restart caddy', sudo: true);

    expect($transport->commands())->toContain('sudo systemctl restart caddy');
});

test('sudo is not prefixed for root', function () {
    $server = Server::factory()->create(['ssh_user' => 'root', 'host_key_fingerprint' => null]);
    $transport = new FakeSSHTransport;

    connectTo($server, $transport)->execute('systemctl restart caddy', sudo: true);

    expect($transport->commands())->toContain('systemctl restart caddy');
});

test('commands are logged without their output', function () {
    $server = Server::factory()->create(['host_key_fingerprint' => null]);
    $transport = (new FakeSSHTransport)->queueResult('SENSITIVE-OUTPUT', 0);

    connectTo($server, $transport)->execute('cat .env');

    $activity = Activity::where('description', 'ssh_command_executed')->latest('id')->first();

    expect($activity)->not->toBeNull()
        ->and($activity->properties['command'])->toBe('cat .env')
        ->and($activity->properties['exit_code'])->toBe(0)
        ->and($activity->properties['output_length'])->toBe(16)
        ->and(json_encode($activity->properties))->not->toContain('SENSITIVE-OUTPUT');
});

test('a failing command returns a failed result', function () {
    $server = Server::factory()->create(['host_key_fingerprint' => null]);
    $transport = (new FakeSSHTransport)->queueResult('not found', 127);

    expect(connectTo($server, $transport)->execute('nope')->failed())->toBeTrue();
});

test('stream forwards output chunks to the callback', function () {
    $server = Server::factory()->create(['host_key_fingerprint' => null]);
    $transport = (new FakeSSHTransport)->queueResult('building...', 0);
    $chunks = [];

    connectTo($server, $transport)->stream('bun run build', function (string $chunk) use (&$chunks) {
        $chunks[] = $chunk;
    });

    expect($chunks)->toBe(['building...']);
});

test('uploading content records the destination', function () {
    $server = Server::factory()->create(['host_key_fingerprint' => null]);
    $transport = new FakeSSHTransport;

    connectTo($server, $transport)->uploadContent('Caddyfile body', '/etc/caddy/Caddyfile');

    expect($transport->uploads())->toBe(['/etc/caddy/Caddyfile' => 'Caddyfile body']);
});

test('disconnecting closes the transport', function () {
    $server = Server::factory()->create(['host_key_fingerprint' => null]);
    $transport = new FakeSSHTransport;

    $connection = connectTo($server, $transport);
    expect($transport->connected())->toBeTrue();

    $connection->disconnect();
    expect($transport->connected())->toBeFalse();
});
