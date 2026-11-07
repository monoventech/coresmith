<?php

declare(strict_types=1);

use App\Enums\ServerStatus;
use App\Exceptions\SSHCommandFailedException;
use App\Jobs\FixAuditFinding;
use App\Models\Server;
use App\Services\SSH\SSHConnectionManager;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\FakeSSHTransport;

function fixRun(Server $server, string $fix, FakeSSHTransport $transport): void
{
    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $transport);

    try {
        app()->call([new FixAuditFinding($server, $fix), 'handle']);
    } finally {
        SSHConnectionManager::createTransportUsing(null);
    }
}

test('stopping a web server stops and disables it, then checks the server again', function () {
    $server = Server::factory()->create(['status' => ServerStatus::Pending, 'host_key_fingerprint' => null]);
    $transport = (new FakeSSHTransport)->respondTo('base64 -d', "== ports\nLISTEN 0 4096 0.0.0.0:22 0.0.0.0:* users:((\"sshd\",pid=701,fd=3))\n== services\ncron.service\n== disk\n41943040\n== memory\n4028000\n");

    fixRun($server, 'stop-nginx', $transport);

    $commands = $transport->commands();

    expect($commands[0])->toBe("systemctl stop 'nginx'")
        ->and($commands[1])->toBe("systemctl disable 'nginx'")
        ->and($server->fresh()->audit['state'])->toBe('done')
        ->and(collect($server->fresh()->audit['findings'])->firstWhere('key', 'ports_free'))->not->toBeNull()
        ->and(Activity::query()->where('description', 'audit_fix_applied')->sole()->properties['service'])->toBe('nginx');
});

test('a stop that fails is stored as the reason the check did not finish', function () {
    $server = Server::factory()->create(['status' => ServerStatus::Pending, 'host_key_fingerprint' => null]);
    $transport = (new FakeSSHTransport)->respondTo('systemctl stop', 'Failed to stop nginx.service: Access denied', 1);

    expect(fn() => fixRun($server, 'stop-nginx', $transport))->toThrow(SSHCommandFailedException::class);

    expect($server->fresh()->audit['state'])->toBe('failed')
        ->and($server->fresh()->audit['error'])->toBe('Stopping nginx did not work: Failed to stop nginx.service: Access denied');
});

test('a disable that fails after a successful stop names disabling as what failed', function () {
    $server = Server::factory()->create(['status' => ServerStatus::Pending, 'host_key_fingerprint' => null]);
    $transport = (new FakeSSHTransport)->respondTo('systemctl disable', 'Failed to disable nginx.service: Access denied', 1);

    expect(fn() => fixRun($server, 'stop-nginx', $transport))->toThrow(SSHCommandFailedException::class);

    expect($server->fresh()->audit['state'])->toBe('failed')
        ->and($server->fresh()->audit['error'])->toBe('Disabling nginx did not work: Failed to disable nginx.service: Access denied');
});

test('a re-check that fails after a successful stop and disable does not blame the stop', function () {
    $server = Server::factory()->create(['status' => ServerStatus::Pending, 'host_key_fingerprint' => null]);
    $transport = (new FakeSSHTransport)->respondTo('base64 -d', 'Connection reset while checking the server', 1);

    expect(fn() => fixRun($server, 'stop-nginx', $transport))->toThrow(SSHCommandFailedException::class);

    $commands = $transport->commands();

    expect($commands[0])->toBe("systemctl stop 'nginx'")
        ->and($commands[1])->toBe("systemctl disable 'nginx'")
        ->and($server->fresh()->audit['state'])->toBe('failed')
        ->and($server->fresh()->audit['error'])->toBe('nginx was stopped and disabled, but checking the server again failed: Connection reset while checking the server. Check again.')
        ->and(Activity::query()->where('description', 'audit_fix_applied')->sole()->properties['service'])->toBe('nginx');
});

test('only the known web server fixes exist', function () {
    expect(fn() => new FixAuditFinding(Server::factory()->create(), 'stop-sshd'))->toThrow(InvalidArgumentException::class);
});
