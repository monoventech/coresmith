<?php

declare(strict_types=1);

use App\Enums\ServerStatus;
use App\Exceptions\SSHAuthenticationException;
use App\Exceptions\SSHCommandFailedException;
use App\Jobs\AuditServer;
use App\Jobs\ProvisionServer;
use App\Models\Server;
use App\Services\Server\Audit\ServerAudit;
use App\Services\SSH\SSHConnectionManager;
use Illuminate\Database\QueryException;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use phpseclib3\Exception\NoSupportedAlgorithmsException;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\FakeSSHTransport;

function auditJobRun(Server $server, FakeSSHTransport $transport): void
{
    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $transport);

    try {
        app()->call([new AuditServer($server), 'handle']);
    } finally {
        SSHConnectionManager::createTransportUsing(null);
    }
}

function cloudAuditBegin(Server $server, string $probe, bool $provisionAfter = false): FakeSSHTransport
{
    $transport = (new FakeSSHTransport)->respondTo('base64 -d', $probe);

    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $transport);

    try {
        AuditServer::begin($server, provisionAfter: $provisionAfter);
    } finally {
        SSHConnectionManager::createTransportUsing(null);
    }

    return $transport;
}

function cloudAuditWork(AuditServer $job, string $probe): void
{
    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => (new FakeSSHTransport)->respondTo('base64 -d', $probe));

    try {
        app()->call([$job, 'handle']);
    } catch (Throwable $e) {
        $job->failed($e);
    } finally {
        SSHConnectionManager::createTransportUsing(null);
    }
}

function cloudAuditCleanProbe(): string
{
    return "== ports\nLISTEN 0 4096 0.0.0.0:22 0.0.0.0:* users:((\"sshd\",pid=701,fd=3))\n== services\ncron.service\n== disk\n41943040\n== memory\n4028000\n";
}

function cloudAuditNginxProbe(): string
{
    return "== ports\nLISTEN 0 511 0.0.0.0:80 0.0.0.0:* users:((\"nginx\",pid=1,fd=6))\n== services\nnginx.service\n== disk\n41943040\n== memory\n4028000\n";
}

test('a finished check is stored with its findings and logged', function () {
    $server = Server::factory()->create(['status' => ServerStatus::Pending, 'host_key_fingerprint' => null]);
    $transport = (new FakeSSHTransport)->respondTo('base64 -d', "== ports\nLISTEN 0 511 0.0.0.0:80 0.0.0.0:* users:((\"nginx\",pid=1,fd=6))\n== services\nnginx.service\n== disk\n41943040\n== memory\n4028000\n");

    auditJobRun($server, $transport);

    $audit = $server->fresh()->audit;

    expect($audit['state'])->toBe('done')
        ->and($audit['ran_at'])->not->toBeNull()
        ->and(collect($audit['findings'])->firstWhere('key', 'port_80')['severity'])->toBe('blocker')
        ->and(ServerAudit::blocks($server->fresh()))->toBeTrue()
        ->and(ServerAudit::fixes($server->fresh()))->toBe(['stop-nginx'])
        ->and(Activity::query()->where('description', 'server_audited')->sole()->properties->toArray())->toBe(['blockers' => 1, 'warnings' => 0])
        ->and($transport->connected())->toBeFalse();
});

test('a check that cannot sign in says what to do', function () {
    $server = Server::factory()->create(['status' => ServerStatus::Pending, 'host_key_fingerprint' => null, 'ssh_user' => 'root']);

    expect(fn() => auditJobRun($server, (new FakeSSHTransport)->failLogin()))->toThrow(SSHAuthenticationException::class);

    expect($server->fresh()->audit)->toMatchArray(['state' => 'failed'])
        ->and($server->fresh()->audit['error'])->toBe('Coresmith could not sign in as root. Check the public key above is in ~/.ssh/authorized_keys, then check again.');
});

test('a check that cannot use sudo names passwordless sudo as the fix', function () {
    $server = Server::factory()->make(['ssh_user' => 'deploy', 'name' => 'web-1']);
    $e = new SSHCommandFailedException('sudo: a terminal is required to read the password', 1);

    expect(AuditServer::explain($server, $e))->toBe('Coresmith could not run the check as root on web-1. Give deploy passwordless sudo, or connect as root, then check again.');
});

test('a check that failed on the server shows what the server said', function () {
    $server = Server::factory()->make(['ssh_user' => 'root']);
    $e = new SSHCommandFailedException("bash: base64: command not found\n", 127);

    expect(AuditServer::explain($server, $e))->toBe('The check stopped: bash: base64: command not found Fix that on the server, then check again.');
});

test('a check whose job dies outside handle is stored as failed', function () {
    $server = Server::factory()->create(['status' => ServerStatus::Pending, 'host_key_fingerprint' => null]);

    ServerAudit::start($server);
    (new AuditServer($server))->failed(new MaxAttemptsExceededException('Job has timed out.'));

    expect($server->fresh()->audit['state'])->toBe('failed')
        ->and($server->fresh()->audit['error'])->toContain('Job has timed out.')
        ->and(ServerAudit::isRunning($server->fresh()))->toBeFalse();
});

test('a check that never reported back stops counting as running', function () {
    $server = Server::factory()->create(['audit' => ['state' => 'running', 'started_at' => now()->subMinutes(6)->toIso8601String()]]);

    expect(ServerAudit::isRunning($server))->toBeFalse();

    $server->forceFill(['audit' => ['state' => 'running', 'started_at' => now()->subMinutes(1)->toIso8601String()]])->save();

    expect(ServerAudit::isRunning($server))->toBeTrue();
});

test('a check older than an hour is no longer fresh', function () {
    $server = Server::factory()->create(['audit' => ['state' => 'done', 'ran_at' => now()->subMinutes(61)->toIso8601String(), 'findings' => []]]);

    expect(ServerAudit::isFresh($server))->toBeFalse();

    $server->forceFill(['audit' => ['state' => 'done', 'ran_at' => now()->subMinutes(5)->toIso8601String(), 'findings' => []]])->save();

    expect(ServerAudit::isFresh($server))->toBeTrue()
        ->and(ServerAudit::blocks($server))->toBeFalse();
});

test('a failure that merely mentions sudo in a warning is not blamed on sudo', function () {
    $server = Server::factory()->make(['name' => 'web-1', 'ssh_user' => 'deploy']);
    $e = new SSHCommandFailedException("sudo: unable to resolve host web-1\nss: command not found", 127);

    expect(AuditServer::explain($server, $e))
        ->toContain('ss: command not found')
        ->and(AuditServer::explain($server, $e))->not->toContain('passwordless sudo');
});

test('a server that only offers outdated ssh algorithms is told what to update', function () {
    $server = Server::factory()->make(['name' => 'web-1']);
    $e = new NoSupportedAlgorithmsException('No compatible key exchange algorithms found');

    expect(AuditServer::explain($server, $e))->toBe('web-1 offers no SSH algorithms Coresmith trusts. Update OpenSSH on the server so it allows curve25519 key exchange and AES-GCM or ChaCha20 ciphers, then check again.');
});

test('an audit asked to provision afterwards starts provisioning when nothing blocks', function () {
    Queue::fake([ProvisionServer::class]);
    $server = Server::factory()->create(['status' => ServerStatus::Pending, 'host_key_fingerprint' => null]);

    $transport = cloudAuditBegin($server, cloudAuditCleanProbe(), provisionAfter: true);

    expect($server->fresh())
        ->status->toBe(ServerStatus::Provisioning)
        ->and($server->fresh()->audit['state'])->toBe('done')
        ->and(ServerAudit::blocks($server->fresh()))->toBeFalse()
        ->and($transport->connected())->toBeFalse();
    Queue::assertPushed(ProvisionServer::class, 1);
    Queue::assertPushed(ProvisionServer::class, fn(ProvisionServer $job): bool => (fn() => $this->server)->call($job)->is($server)
        && (fn() => $this->fromScratch)->call($job) === false);
});

test('an audit asked to provision afterwards stops at a blocker', function () {
    Queue::fake([ProvisionServer::class]);
    $server = Server::factory()->create(['status' => ServerStatus::Pending, 'host_key_fingerprint' => null]);

    cloudAuditBegin($server, cloudAuditNginxProbe(), provisionAfter: true);

    expect($server->fresh()->status)->toBe(ServerStatus::Pending)
        ->and($server->fresh()->audit['state'])->toBe('done')
        ->and(ServerAudit::blocks($server->fresh()))->toBeTrue();
    Queue::assertNotPushed(ProvisionServer::class);
});

test('an ordinary audit never provisions by itself', function () {
    Queue::fake([ProvisionServer::class]);
    $server = Server::factory()->create(['status' => ServerStatus::Pending, 'host_key_fingerprint' => null]);

    cloudAuditBegin($server, cloudAuditCleanProbe());

    expect($server->fresh()->status)->toBe(ServerStatus::Pending)
        ->and($server->fresh()->audit['state'])->toBe('done')
        ->and(ServerAudit::blocks($server->fresh()))->toBeFalse();
    Queue::assertNotPushed(ProvisionServer::class);
});

test('an audit asked to provision afterwards leaves a server that moved on alone', function (Closure $moveOn, ServerStatus $status) {
    Queue::fake([ProvisionServer::class]);
    $server = Server::factory()->create(['status' => ServerStatus::Pending, 'host_key_fingerprint' => null]);
    $moveOn($server);

    cloudAuditBegin($server, cloudAuditCleanProbe(), provisionAfter: true);

    expect(Server::withTrashed()->findOrFail($server->getKey()))
        ->status->toBe($status)
        ->audit->toMatchArray(['state' => 'done']);
    Queue::assertNotPushed(ProvisionServer::class);
})->with([
    'someone already started provisioning it' => [fn(Server $server) => $server->forceFill(['status' => ServerStatus::Provisioning])->save(), ServerStatus::Provisioning],
    'it was marked disconnected' => [fn(Server $server) => $server->forceFill(['status' => ServerStatus::Disconnected])->save(), ServerStatus::Disconnected],
    'it was detached while the audit ran' => [fn(Server $server) => $server->delete(), ServerStatus::Pending],
]);

test('an audit whose hand-off to provisioning fails stays finished and reports why', function () {
    Exceptions::fake();
    config(['queue.connections.unqueueable' => ['driver' => 'database', 'table' => 'no_such_jobs', 'queue' => 'provisioning'], 'queue.default' => 'unqueueable']);
    $server = Server::factory()->create(['status' => ServerStatus::Pending, 'host_key_fingerprint' => null]);

    cloudAuditWork(new AuditServer($server, provisionAfter: true), cloudAuditCleanProbe());

    expect($server->fresh())
        ->status->toBe(ServerStatus::Pending)
        ->audit->toMatchArray(['state' => 'done'])
        ->and(ServerAudit::blocks($server->fresh()))->toBeFalse();
    Exceptions::assertReported(QueryException::class);
});

test('an audit queued before it could hand over to provisioning runs as a plain audit', function () {
    Queue::fake([ProvisionServer::class]);
    $server = Server::factory()->create(['status' => ServerStatus::Pending, 'host_key_fingerprint' => null]);
    $values = (new AuditServer($server, provisionAfter: true))->__serialize();
    unset($values["\0" . AuditServer::class . "\0provisionAfter"]);
    $job = unserialize('O:' . strlen(AuditServer::class) . ':"' . AuditServer::class . '":' . substr(serialize($values), 2));

    cloudAuditWork($job, cloudAuditCleanProbe());

    expect($job)->toBeInstanceOf(AuditServer::class)
        ->and($server->fresh())
        ->status->toBe(ServerStatus::Pending)
        ->audit->toMatchArray(['state' => 'done']);
    Queue::assertNotPushed(ProvisionServer::class);
});
