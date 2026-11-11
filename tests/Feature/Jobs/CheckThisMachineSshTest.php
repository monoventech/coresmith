<?php

declare(strict_types=1);

use App\Jobs\CheckThisMachineSsh;
use App\Models\Server;
use App\Services\SSH\SSHConnectionManager;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Tests\Support\FakeSSHTransport;

function checkThisMachineSshServer(array $meta = ['ssh_hardening' => 'owner'], bool $runsCoresmith = true): Server
{
    return Server::factory()->create(['host_key_fingerprint' => null, 'os_name' => 'ubuntu', 'runs_coresmith' => $runsCoresmith, 'ip_address' => '127.0.0.1', 'meta' => $meta]);
}

function checkThisMachineSshRun(Server $server, FakeSSHTransport $transport): FakeSSHTransport
{
    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $transport);
    app()->call([new CheckThisMachineSsh($server), 'handle']);

    return $transport;
}

afterEach(function () {
    SSHConnectionManager::createTransportUsing(null);
});

test('a check reads sshd and records what is on, without changing anything', function () {
    $this->freezeTime();
    $server = checkThisMachineSshServer(['ssh_hardening' => 'owner', 'ssh_passwords' => 'off', 'cpu_cores' => 8]);
    $transport = checkThisMachineSshRun($server, (new FakeSSHTransport)->respondTo(
        'sshd -T',
        "permitrootlogin yes\npasswordauthentication yes\nkbdinteractiveauthentication no\n== files\n/etc/ssh/sshd_config.d/50-cloud-init.conf\tpasswordauthentication\tyes\n",
    ));
    $commands = implode("\n", $transport->commands());

    expect($commands)->toContain('sshd -T')
        ->and($commands)->not->toContain('reload')
        ->and($commands)->not->toContain('rm ')
        ->and($transport->uploads())->toBe([])
        ->and($server->fresh()->meta)->toBeInAnyKeyOrder([
            'ssh_hardening' => 'owner',
            'cpu_cores' => 8,
            'ssh_passwords' => 'on',
            'ssh_password_logins' => ['password', 'root'],
            'ssh_password_sources' => ['password' => '/etc/ssh/sshd_config.d/50-cloud-init.conf'],
            'ssh_passwords_checked_at' => now()->toIso8601String(),
        ])
        ->and($server->fresh()->sshPasswordsOn())->toBeTrue();
});

test('turning password login off is noticed on the next check', function () {
    $this->freezeTime();
    $server = checkThisMachineSshServer(['ssh_hardening' => 'owner', 'ssh_passwords' => 'on', 'ssh_password_logins' => ['password'], 'ssh_password_sources' => []]);

    checkThisMachineSshRun($server, (new FakeSSHTransport)->respondTo('sshd -T', "permitrootlogin no\npasswordauthentication no\n== files\n"));

    expect($server->fresh()->meta)->toBe(['ssh_hardening' => 'owner', 'ssh_passwords' => 'off', 'ssh_passwords_checked_at' => now()->toIso8601String()])
        ->and($server->fresh()->sshPasswordsOff())->toBeTrue()
        ->and($server->fresh()->sshPasswordsOn())->toBeFalse();
});

test('a check that cannot read sshd keeps what was recorded', function (FakeSSHTransport $transport) {
    $meta = ['ssh_hardening' => 'owner', 'ssh_passwords' => 'on', 'ssh_password_logins' => ['password'], 'ssh_password_sources' => []];
    $server = checkThisMachineSshServer($meta);

    checkThisMachineSshRun($server, $transport);

    expect($server->fresh()->meta)->toBe($meta);
})->with([
    'sshd reports nothing' => fn() => (new FakeSSHTransport)->respondTo('sshd -T', "== files\n", 255),
    'the machine refuses the connection' => fn() => (new FakeSSHTransport)->refuseConnections(),
]);

test('only a machine whose SSH is left to its owner is checked', function (array $meta, bool $runsCoresmith) {
    $server = checkThisMachineSshServer($meta, $runsCoresmith);
    $transport = checkThisMachineSshRun($server, new FakeSSHTransport);

    expect($transport->commands())->toBe([])
        ->and($server->fresh()->meta)->toBe($meta);
})->with([
    'this machine before the hand-back' => [['ssh_hardening' => 'verified'], true],
    'a server Coresmith manages' => [['ssh_hardening' => 'verified'], false],
]);

test('a machine is never checked by two jobs at once', function () {
    $server = Server::factory()->make(['id' => '01JC0000000000000000000000']);

    expect(new CheckThisMachineSsh($server))->toBeInstanceOf(ShouldBeUnique::class)
        ->and((new CheckThisMachineSsh($server))->uniqueId())->toBe('01JC0000000000000000000000');
});
