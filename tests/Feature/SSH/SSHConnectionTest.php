<?php

declare(strict_types=1);

use App\Exceptions\HostKeyChangedException;
use App\Exceptions\SSHAuthenticationException;
use App\Exceptions\SSHCommandFailedException;
use App\Models\Server;
use App\Services\SSH\HostKeyFingerprint;
use App\Services\SSH\SSHConnection;
use phpseclib3\Exception\ConnectionClosedException;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\FakeSSHTransport;

function connectTo(Server $server, FakeSSHTransport $transport): SSHConnection
{
    return (new SSHConnection($server, $transport))->connect();
}

test('the first connection records the host key fingerprint', function () {
    $server = Server::factory()->create(['host_key_fingerprint' => null]);
    $transport = (new FakeSSHTransport)->setHostKey(FakeSSHTransport::keyNamed('host-key-material'));

    connectTo($server, $transport);

    expect($server->fresh()->host_key_fingerprint)
        ->toBe(FakeSSHTransport::fingerprintOf('host-key-material'));
});

test('a matching host key connects', function () {
    $transport = (new FakeSSHTransport)->setHostKey(FakeSSHTransport::keyNamed('host-key-material'));
    $server = Server::factory()->create([
        'host_key_fingerprint' => FakeSSHTransport::fingerprintOf('host-key-material'),
    ]);

    expect(connectTo($server, $transport)->execute('whoami')->successful())->toBeTrue();
});

test('a changed host key is refused before authenticating', function () {
    $transport = (new FakeSSHTransport)->setHostKey(FakeSSHTransport::keyNamed('attacker-key'));
    $server = Server::factory()->create([
        'host_key_fingerprint' => FakeSSHTransport::fingerprintOf('original-key'),
    ]);

    try {
        connectTo($server, $transport);
        $this->fail('Expected HostKeyChangedException');
    } catch (HostKeyChangedException $e) {
        expect($transport->loginAttempted())->toBeFalse()
            ->and($e->expected())->toBe(FakeSSHTransport::fingerprintOf('original-key'))
            ->and($e->actual())->toBe(FakeSSHTransport::fingerprintOf('attacker-key'));
    }
});

test('a changed host key is logged as a security event', function () {
    $transport = (new FakeSSHTransport)->setHostKey(FakeSSHTransport::keyNamed('attacker-key'));
    $server = Server::factory()->create([
        'host_key_fingerprint' => FakeSSHTransport::fingerprintOf('original-key'),
    ]);

    try {
        connectTo($server, $transport);
    } catch (HostKeyChangedException) {
        // asserted below
    }

    expect(Activity::where('description', 'ssh_host_key_changed')->count())->toBe(1);
});

test('the fingerprint recorded is the one ssh-keygen prints for the host key', function () {
    $server = Server::factory()->create(['host_key_fingerprint' => null]);

    connectTo($server, (new FakeSSHTransport)->setHostKey('ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIBBoM6eEh29z9GyfaiV/OH8xLRwdtOyw0trj85BenDW4'));

    expect($server->fresh()->host_key_fingerprint)->toBe('SHA256:4tuIdFPGvascdN/EivEGNJyqg/5BjbbXAHuVWTItMk0');
});

test('an RSA host key is recorded by the key, whichever signature algorithm carried it', function () {
    $blob = 'AAAAB3NzaC1yc2EAAAADAQABAAAAgQCv18shDIVgYG9U8I6NPjovihEbadZjGC5VvpnRd9tqhs4adk1IvYIbRhIdeXDZiG0WWkgA8eB17o80GIXMpW/Qj1TFnCyHhO+rhu2NOq//aIkVUoE3XYsEzPxo0C5cgHKC5e4aoEuY3DFA+fWQWnb+bmvxquJdgQhG4RqoY7c0ww==';
    $server = Server::factory()->create(['host_key_fingerprint' => null]);

    connectTo($server, (new FakeSSHTransport)->setHostKey("rsa-sha2-512 {$blob}"));
    connectTo($server->fresh(), (new FakeSSHTransport)->setHostKey("rsa-sha2-256 {$blob}"));

    expect($server->fresh())
        ->host_key_fingerprint->toBe('SHA256:g/kGi3cfVVisrykdQLnRg+GiSfJih8/yIzKY+ODHA/0')
        ->pending_host_key_fingerprint->toBeNull();
});

test('a fingerprint recorded in the old format is replaced by the OpenSSH one on the next connection, without a review', function () {
    $hostKey = FakeSSHTransport::keyNamed('host-key-material');
    $server = Server::factory()->create(['host_key_fingerprint' => HostKeyFingerprint::legacy($hostKey)]);

    expect(connectTo($server, (new FakeSSHTransport)->setHostKey($hostKey))->execute('whoami')->successful())->toBeTrue();

    expect($server->fresh())
        ->host_key_fingerprint->toBe(FakeSSHTransport::fingerprintOf('host-key-material'))
        ->pending_host_key_fingerprint->toBeNull()
        ->host_key_changed_at->toBeNull()
        ->and(Activity::where('description', 'ssh_host_key_changed')->count())->toBe(0);
});

test('a different key is still a change when the fingerprint on record is in the old format', function () {
    $recorded = HostKeyFingerprint::legacy(FakeSSHTransport::keyNamed('original-key'));
    $server = Server::factory()->create(['host_key_fingerprint' => $recorded]);
    $transport = (new FakeSSHTransport)->setHostKey(FakeSSHTransport::keyNamed('attacker-key'));

    expect(fn() => connectTo($server, $transport))->toThrow(HostKeyChangedException::class);

    expect($server->fresh())
        ->host_key_fingerprint->toBe($recorded)
        ->pending_host_key_fingerprint->toBe(FakeSSHTransport::fingerprintOf('attacker-key'))
        ->and($transport->loginAttempted())->toBeFalse();
});

test('a host key that cannot be read is refused before signing in, and nothing is trusted', function (string $hostKey) {
    $server = Server::factory()->create(['host_key_fingerprint' => null]);
    $transport = (new FakeSSHTransport)->setHostKey($hostKey);

    expect(fn() => connectTo($server, $transport))->toThrow(ConnectionClosedException::class);

    expect($server->fresh()->host_key_fingerprint)->toBeNull()
        ->and($transport->loginAttempted())->toBeFalse();
})->with(['nothing' => '', 'a type alone' => 'ssh-ed25519', 'a bare word' => 'fake-host-key', 'a key that is not base64' => 'ssh-ed25519 not*base64']);

test('a host key that cannot be read is not mistaken for a changed one', function () {
    $server = Server::factory()->create(['host_key_fingerprint' => FakeSSHTransport::fingerprintOf('original-key')]);

    expect(fn() => connectTo($server, (new FakeSSHTransport)->setHostKey('')))->toThrow(ConnectionClosedException::class);

    expect($server->fresh())
        ->host_key_fingerprint->toBe(FakeSSHTransport::fingerprintOf('original-key'))
        ->pending_host_key_fingerprint->toBeNull()
        ->and(Activity::where('description', 'ssh_host_key_changed')->count())->toBe(0);
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

test('a compound command runs whole as root for a non-root user', function () {
    $server = Server::factory()->create(['ssh_user' => 'deploy', 'host_key_fingerprint' => null]);
    $transport = new FakeSSHTransport;

    connectTo($server, $transport)->execute('systemctl enable --now auditd && augenrules --load || true', sudo: true);

    expect($transport->commands())->toContain("sudo bash -c 'systemctl enable --now auditd && augenrules --load || true'");
});

test('a command that starts its own shell is only prefixed with sudo', function () {
    $server = Server::factory()->create(['ssh_user' => 'deploy', 'host_key_fingerprint' => null]);
    $transport = new FakeSSHTransport;

    connectTo($server, $transport)->execute("sh -c 'test -f /etc/x || touch /etc/x'", sudo: true);

    expect($transport->commands())->toContain("sudo sh -c 'test -f /etc/x || touch /etc/x'");
});

test('a compound command runs unchanged for root', function () {
    $server = Server::factory()->create(['ssh_user' => 'root', 'host_key_fingerprint' => null]);
    $transport = new FakeSSHTransport;

    connectTo($server, $transport)->execute('cat /tmp/a | crontab -u someone -', sudo: true);

    expect($transport->commands())->toBe(['cat /tmp/a | crontab -u someone -']);
});

test('a root upload goes straight to its path', function () {
    $server = Server::factory()->create(['ssh_user' => 'root', 'host_key_fingerprint' => null]);
    $transport = new FakeSSHTransport;

    connectTo($server, $transport)->uploadContent('[Unit]', '/etc/systemd/system/x.service');

    expect($transport->uploads())->toBe(['/etc/systemd/system/x.service' => '[Unit]'])
        ->and($transport->commands())->toBe([]);
});

test('a non-root upload is staged and copied into place as root, keeping an existing file\'s owner and mode', function () {
    $server = Server::factory()->create(['ssh_user' => 'deploy', 'host_key_fingerprint' => null]);
    $transport = new FakeSSHTransport;

    connectTo($server, $transport)->uploadContent('[Unit]', '/etc/systemd/system/x.service');

    $staged = FakeSSHTransport::STAGED_UPLOAD;

    expect($transport->uploads())->toBe([$staged => '[Unit]'])
        ->and($transport->commands())->toBe([
            'mktemp ' . SSHConnection::STAGING_TEMPLATE,
            'sudo bash -c ' . escapeshellarg("if [ -e '/etc/systemd/system/x.service' ]; then cat '{$staged}' > '/etc/systemd/system/x.service'; else install -m 644 '{$staged}' '/etc/systemd/system/x.service'; fi"),
            "rm -f '{$staged}'",
        ]);
});

test('a staged upload runs each of its commands within the time it is given', function (?int $timeout, int $budget) {
    $server = Server::factory()->create(['ssh_user' => 'deploy', 'host_key_fingerprint' => null]);
    $transport = new FakeSSHTransport;
    $connection = connectTo($server, $transport);

    $timeout === null ? $connection->uploadContent('x', '/etc/x') : $connection->uploadContent('x', '/etc/x', timeout: $timeout);

    expect([$transport->budgetFor('mktemp '), $transport->budgetFor('then cat '), $transport->budgetFor("rm -f '" . FakeSSHTransport::STAGED_UPLOAD)])->toBe([$budget, $budget, $budget]);
})->with([
    'a short budget' => [10, 10],
    'the default' => [null, 600],
]);

test('a staged upload is removed even when it cannot be copied into place', function () {
    $server = Server::factory()->create(['ssh_user' => 'deploy', 'host_key_fingerprint' => null]);
    $transport = (new FakeSSHTransport)->respondTo('install -m 644', 'install: cannot create regular file', 1);

    expect(fn() => connectTo($server, $transport)->uploadContent('x', '/etc/x'))->toThrow(SSHCommandFailedException::class);

    $commands = $transport->commands();

    expect(end($commands))->toBe("rm -f '" . FakeSSHTransport::STAGED_UPLOAD . "'");
});

test('an upload is refused when the server does not hand back a staging file', function () {
    $server = Server::factory()->create(['ssh_user' => 'deploy', 'host_key_fingerprint' => null]);
    $transport = (new FakeSSHTransport)->respondTo('mktemp', "/home/deploy/elsewhere\n");

    expect(fn() => connectTo($server, $transport)->uploadContent('x', '/etc/x'))->toThrow(RuntimeException::class, 'Could not stage the upload for [/etc/x]');

    expect($transport->uploads())->toBe([]);
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

test('a token inside a logged command is redacted', function () {
    $server = Server::factory()->create(['host_key_fingerprint' => null]);

    connectTo($server, new FakeSSHTransport)->execute("git clone --bare 'https://x-access-token:ghp_abcdefghijklmnopqrstuvwxyz012345@github.com/acme/app.git' /tmp/repo");

    $activity = Activity::where('description', 'ssh_command_executed')->latest('id')->first();

    expect($activity->properties['command'])->not->toContain('ghp_abcdefghijklmnopqrstuvwxyz012345')
        ->and($activity->properties['command'])->toContain('github.com/acme/app.git');
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

test('a dropped connection reconnects and re-authenticates on the next command', function () {
    $server = Server::factory()->create(['host_key_fingerprint' => null]);
    $transport = (new FakeSSHTransport)->dropConnectionOn('git fetch');
    $connection = connectTo($server, $transport);

    expect(fn() => $connection->execute('git fetch'))->toThrow(ConnectionClosedException::class);
    expect($transport->connected())->toBeFalse();

    $connection->execute('git fetch');

    expect($transport->connected())->toBeTrue();
});

test('disconnecting closes the transport', function () {
    $server = Server::factory()->create(['host_key_fingerprint' => null]);
    $transport = new FakeSSHTransport;

    $connection = connectTo($server, $transport);
    expect($transport->connected())->toBeTrue();

    $connection->disconnect();
    expect($transport->connected())->toBeFalse();
});
