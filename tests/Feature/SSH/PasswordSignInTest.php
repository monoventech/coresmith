<?php

declare(strict_types=1);

use App\Exceptions\HostKeyChangedException;
use App\Exceptions\SSHPasswordRefused;
use App\Models\Server;
use App\Services\SSH\SSHConnection;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\FakeSSHTransport;
use Tests\Support\TraceSecrets;

function passwordSession(Server $server, FakeSSHTransport $transport): SSHConnection
{
    return new SSHConnection($server, $transport);
}

test('a password sign-in records the host key the first time it meets the server', function () {
    $server = Server::factory()->create(['host_key_fingerprint' => null]);
    $transport = (new FakeSSHTransport)->setHostKey(FakeSSHTransport::keyNamed('first-meeting'))->acceptPassword('maya', 'correct horse');

    passwordSession($server, $transport)->signInWithPassword('maya', 'correct horse');

    expect($server->fresh()->host_key_fingerprint)->toBe(FakeSSHTransport::fingerprintOf('first-meeting'))
        ->and($transport->passwordLogins())->toBe(['maya']);
});

test('a changed host key is refused before the password is sent', function () {
    $server = Server::factory()->create(['host_key_fingerprint' => FakeSSHTransport::fingerprintOf('original-key')]);
    $transport = (new FakeSSHTransport)->setHostKey(FakeSSHTransport::keyNamed('attacker-key'))->acceptPassword('maya', 'correct horse');

    expect(fn() => passwordSession($server, $transport)->signInWithPassword('maya', 'correct horse'))
        ->toThrow(HostKeyChangedException::class);

    expect($transport->passwordLogins())->toBe([]);
});

test('a wrong password is refused and says the server takes passwords', function () {
    $server = Server::factory()->create(['host_key_fingerprint' => null]);
    $transport = (new FakeSSHTransport)->acceptPassword('maya', 'correct horse');

    $error = TraceSecrets::thrownBy(fn() => passwordSession($server, $transport)->signInWithPassword('maya', 'wrong horse'));

    expect($error)->toBeInstanceOf(SSHPasswordRefused::class)
        ->and($error->passwordsAllowed)->toBeTrue()
        ->and($error->getMessage())->not->toContain('wrong horse')
        ->and(TraceSecrets::held($error, 'wrong horse'))->toBeFalse();
});

test('a server that turned password logins off is told apart from a wrong password', function () {
    $server = Server::factory()->create(['host_key_fingerprint' => null]);
    $transport = (new FakeSSHTransport)->refusePasswords();

    try {
        passwordSession($server, $transport)->signInWithPassword('maya', 'correct horse');
        $this->fail('Expected SSHPasswordRefused');
    } catch (SSHPasswordRefused $refused) {
        expect($refused->passwordsAllowed)->toBeFalse();
    }
});

test('input goes to the command on stdin, never into its command line or the activity log', function () {
    $server = Server::factory()->create(['host_key_fingerprint' => null]);
    $transport = (new FakeSSHTransport)->acceptPassword('maya', 'correct horse');
    $session = passwordSession($server, $transport)->signInWithPassword('maya', 'correct horse');

    $result = $session->executeWithInput("sudo -S -p '' true", "correct horse\n");

    $logged = Activity::query()->where('description', SSHConnection::COMMAND_ACTIVITY)->get();

    expect($result->successful())->toBeTrue()
        ->and($transport->inputFor('sudo -S'))->toBe("correct horse\n")
        ->and(implode("\n", $transport->commands()))->not->toContain('correct horse')
        ->and($logged)->toHaveCount(1)
        ->and(json_encode($logged->first()->properties))->not->toContain('correct horse');
});
