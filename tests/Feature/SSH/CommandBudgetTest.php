<?php

declare(strict_types=1);

use App\Exceptions\SSHCommandTimedOut;
use App\Models\Server;
use App\Services\SSH\Contracts\SSHTransport;
use App\Services\SSH\SSHConnection;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\FakeSSHTransport;

function budgetConnection(FakeSSHTransport $transport): SSHConnection
{
    $server = Server::factory()->create(['host_key_fingerprint' => null]);

    return (new SSHConnection($server, $transport))->connect();
}

test('a command gets ten minutes unless its caller asks for more', function () {
    $transport = new FakeSSHTransport;
    $connection = budgetConnection($transport);

    $connection->execute('uptime');
    $connection->execute('asdf install php 8.5.10', timeout: 1800);

    expect(SSHTransport::COMMAND_SECONDS)->toBe(600)
        ->and($transport->budgetFor('uptime'))->toBe(600)
        ->and($transport->budgetFor('asdf install'))->toBe(1800);
});

test('a command that runs out of time is stopped, not reported as a success', function () {
    $transport = (new FakeSSHTransport)->timeOutOn('composer install', "Installing dependencies from lock file\n");
    $connection = budgetConnection($transport);

    expect(fn() => $connection->execute('cd /var/coresmith/apps/shop/releases/1 && PATH=/opt/asdf-data/installs/php/8.5.10/bin:$PATH composer install --no-dev'))
        ->toThrow(function (SSHCommandTimedOut $e) {
            expect($e->seconds)->toBe(600)
                ->and($e->partialOutput)->toBe("Installing dependencies from lock file\n")
                ->and($e->getMessage())->toBe('`composer install --no-dev` ran for 10 minutes without finishing, so Coresmith stopped it. Check what it was waiting on, then try again.');
        });
});

test('a timed out connection is dropped so the next command gets a fresh channel', function () {
    $transport = (new FakeSSHTransport)->timeOutOn('sleep 9999');
    $connection = budgetConnection($transport);

    expect(fn() => $connection->execute('sleep 9999'))->toThrow(SSHCommandTimedOut::class);
    expect($transport->connected())->toBeFalse();

    $connection->execute('uptime');

    expect($transport->connected())->toBeTrue();
});

test('a timed out command is recorded with no exit code, never the previous command\'s', function () {
    $transport = (new FakeSSHTransport)->timeOutOn('sleep 9999');
    $connection = budgetConnection($transport);

    $connection->execute('true');

    expect(fn() => $connection->execute('sleep 9999'))->toThrow(SSHCommandTimedOut::class);

    expect(Activity::query()->where('description', 'ssh_command_executed')->latest('id')->first()->properties['exit_code'])->toBe(-1);
});

test('a secret in a timed out command never reaches the message', function () {
    $transport = (new FakeSSHTransport)->timeOutOn('git clone');
    $connection = budgetConnection($transport);

    expect(fn() => $connection->execute('git clone --bare https://x-access-token:ghp_secret123@github.com/acme/shop.git /tmp/repo'))
        ->toThrow(fn(SSHCommandTimedOut $e) => expect($e->getMessage())->not->toContain('ghp_secret123'));
});

test('a long budget is described in minutes and a short one in seconds', function () {
    expect(SSHCommandTimedOut::duration(1800))->toBe('30 minutes')
        ->and(SSHCommandTimedOut::duration(60))->toBe('1 minute')
        ->and(SSHCommandTimedOut::duration(45))->toBe('45 seconds');
});
