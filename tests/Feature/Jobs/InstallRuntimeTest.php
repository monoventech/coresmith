<?php

declare(strict_types=1);

use App\Exceptions\SSHCommandFailedException;
use App\Jobs\InstallRuntime;
use App\Models\Server;
use App\Services\Runtime\AsdfManager;
use App\Services\SSH\SSHConnection;
use App\Services\SSH\SSHConnectionManager;
use Tests\Support\FakeSSHTransport;

function runInstallRuntime(Server $server, FakeSSHTransport $transport, string $runtime, string $version): void
{
    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $transport);

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

test('a runtime download that fails on the network is tried twice more, a compile error is not', function () {
    $server = Server::factory()->create(['host_key_fingerprint' => null, 'os_name' => 'ubuntu']);
    $flaky = (new FakeSSHTransport)
        ->respondToSequence('asdf install', [["curl: (28) Operation timed out after 30000 milliseconds\n", 1], ["curl: (6) Could not resolve host: www.php.net\n", 1], ['', 0]]);

    app(AsdfManager::class)->install((new SSHConnection($server, $flaky))->connect(), $server, 'php', '8.5.5');

    expect(count(array_filter($flaky->commands(), fn(string $command): bool => str_contains($command, 'asdf install'))))->toBe(3);

    $broken = (new FakeSSHTransport)->respondTo('asdf install', "configure: error: libxml2 not found\n", 1);

    expect(fn() => app(AsdfManager::class)->install((new SSHConnection($server, $broken))->connect(), $server, 'php', '8.5.5'))->toThrow(SSHCommandFailedException::class);
    expect(count(array_filter($broken->commands(), fn(string $command): bool => str_contains($command, 'asdf install'))))->toBe(1);
});
