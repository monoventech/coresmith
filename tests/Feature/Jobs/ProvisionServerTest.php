<?php

declare(strict_types=1);

use App\Enums\ServerStatus;
use App\Exceptions\SSHCommandFailedException;
use App\Exceptions\UnsupportedOperatingSystemException;
use App\Jobs\ProvisionServer;
use App\Models\Server;
use App\Services\Server\OsDetector;
use App\Services\SSH\SSHConnectionManager;
use Tests\Support\FakeSSHTransport;

function provisioningTransport(): FakeSSHTransport
{
    return (new FakeSSHTransport)
        ->queueResult("ID=ubuntu\nVERSION_ID=\"24.04\"\n", 0)
        ->queueResult('systemd 255', 0)
        ->queueResult("4\n", 0)
        ->queueResult("Mem:           8000        1000        7000\n", 0)
        ->queueResult("/dev/vda1           40G   10G       30G  25% /\n", 0);
}

function provision(Server $server, FakeSSHTransport $transport): void
{
    SSHConnectionManager::createTransportUsing(fn (): FakeSSHTransport => $transport);

    try {
        (new ProvisionServer($server))->handle(app(SSHConnectionManager::class), new OsDetector);
    } finally {
        SSHConnectionManager::createTransportUsing(null);
    }
}

test('phase 1 persists the detected os and hardware', function () {
    $server = Server::factory()->create(['host_key_fingerprint' => null]);

    provision($server, provisioningTransport());

    $fresh = $server->fresh();

    expect($fresh->os_name)->toBe('ubuntu')
        ->and($fresh->os_version)->toBe('24.04')
        ->and($fresh->meta)->toBe(['cpu_cores' => 4, 'memory_mb' => 8000, 'disk_gb' => 40]);
});

test('phase 2 installs base packages through the abstraction', function () {
    $server = Server::factory()->create(['host_key_fingerprint' => null]);
    $transport = provisioningTransport();

    provision($server, $transport);

    $commands = implode("\n", $transport->commands());

    expect($commands)->toContain('apt-get update')
        ->and($commands)->toContain('apt-get install -y')
        ->and($commands)->toContain("'build-essential'");
});

test('both phases are recorded', function () {
    $server = Server::factory()->create(['host_key_fingerprint' => null]);

    provision($server, provisioningTransport());

    expect($server->fresh()->provisioning_phase)->toBe(2);
});

test('a re-run skips already completed phases', function () {
    $server = Server::factory()->create([
        'host_key_fingerprint' => null,
        'os_name' => 'ubuntu',
        'provisioning_phase' => 2,
    ]);
    $transport = new FakeSSHTransport;

    provision($server, $transport);

    expect($transport->commands())->toBe([]);
});

test('a re-run at phase 1 resumes from phase 2 without re-detecting', function () {
    $server = Server::factory()->create([
        'host_key_fingerprint' => null,
        'os_name' => 'ubuntu',
        'provisioning_phase' => 1,
    ]);
    $transport = new FakeSSHTransport;

    provision($server, $transport);

    $commands = implode("\n", $transport->commands());

    expect($commands)->not->toContain('/etc/os-release')
        ->and($commands)->toContain('apt-get update');
});

test('a failure marks the server failed and preserves the completed phase', function () {
    $server = Server::factory()->create(['host_key_fingerprint' => null]);
    $transport = (new FakeSSHTransport)
        ->queueResult("ID=ubuntu\nVERSION_ID=\"24.04\"\n", 0)
        ->queueResult('systemd 255', 0)
        ->queueResult("4\n", 0)
        ->queueResult("Mem:           8000        1000        7000\n", 0)
        ->queueResult("/dev/vda1           40G   10G       30G  25% /\n", 0)
        ->queueResult('E: Could not get lock', 100);

    expect(fn () => provision($server, $transport))->toThrow(SSHCommandFailedException::class);

    $fresh = $server->fresh();

    expect($fresh->status)->toBe(ServerStatus::Failed)
        ->and($fresh->provisioning_phase)->toBe(1)
        ->and($fresh->provisioning_log)->toContain('exit code 100');
});

test('an unsupported distribution fails provisioning cleanly', function () {
    $server = Server::factory()->create(['host_key_fingerprint' => null]);
    $transport = (new FakeSSHTransport)->queueResult("ID=gentoo\nVERSION_ID=\"2\"\n", 0);

    expect(fn () => provision($server, $transport))->toThrow(UnsupportedOperatingSystemException::class);

    expect($server->fresh()->status)->toBe(ServerStatus::Failed)
        ->and($server->fresh()->provisioning_phase)->toBeNull();
});
