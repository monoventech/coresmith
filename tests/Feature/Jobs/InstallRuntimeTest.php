<?php

declare(strict_types=1);

use App\Jobs\InstallRuntime;
use App\Models\Server;
use App\Services\Runtime\AsdfManager;
use App\Services\SSH\SSHConnectionManager;
use Tests\Support\FakeSSHTransport;

function runInstallRuntime(Server $server, FakeSSHTransport $transport, string $runtime, string $version): void
{
    SSHConnectionManager::createTransportUsing(fn (): FakeSSHTransport => $transport);

    try {
        (new InstallRuntime($server, $runtime, $version))->handle(app(SSHConnectionManager::class), new AsdfManager);
    } finally {
        SSHConnectionManager::createTransportUsing(null);
    }
}

test('installing a runtime records it on the server', function () {
    $server = Server::factory()->create(['host_key_fingerprint' => null, 'os_name' => 'ubuntu']);

    runInstallRuntime($server, (new FakeSSHTransport)->queueResult("no\n", 0), 'php', '8.5.5');

    expect($server->fresh()->installed_runtimes)->toBe(['php' => ['8.5.5']]);
});

test('installing a second version keeps both', function () {
    $server = Server::factory()->create([
        'host_key_fingerprint' => null,
        'os_name' => 'ubuntu',
        'installed_runtimes' => ['php' => ['8.4.17']],
    ]);

    runInstallRuntime($server, (new FakeSSHTransport)->queueResult("no\n", 0), 'php', '8.5.5');

    expect($server->fresh()->installed_runtimes)->toBe(['php' => ['8.4.17', '8.5.5']]);
});

test('re-installing an existing version does not duplicate it', function () {
    $server = Server::factory()->create([
        'host_key_fingerprint' => null,
        'os_name' => 'ubuntu',
        'installed_runtimes' => ['php' => ['8.5.5']],
    ]);

    runInstallRuntime($server, (new FakeSSHTransport)->queueResult("yes\n", 0), 'php', '8.5.5');

    expect($server->fresh()->installed_runtimes)->toBe(['php' => ['8.5.5']]);
});

test('different runtimes are tracked separately', function () {
    $server = Server::factory()->create([
        'host_key_fingerprint' => null,
        'os_name' => 'ubuntu',
        'installed_runtimes' => ['php' => ['8.5.5']],
    ]);

    runInstallRuntime($server, (new FakeSSHTransport)->queueResult("no\n", 0), 'nodejs', '24.15.0');

    expect($server->fresh()->installed_runtimes)->toBe(['php' => ['8.5.5'], 'nodejs' => ['24.15.0']]);
});
