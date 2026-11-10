<?php

declare(strict_types=1);

use App\Enums\ServerStatus;
use App\Enums\TeamRole;
use App\Events\ServerProvisioned;
use App\Events\ServerProvisioningFailed;
use App\Exceptions\ProvisioningVerificationException;
use App\Exceptions\SSHCommandFailedException;
use App\Exceptions\UnsupportedOperatingSystemException;
use App\Jobs\ProvisionServer;
use App\Models\Server;
use App\Models\User;
use App\Services\Caddy\CaddyInstaller;
use App\Services\Git\DeployKeyManager;
use App\Services\Server\OsDetector;
use App\Services\Server\SecurityHardening;
use App\Services\SSH\Contracts\SSHTransport;
use App\Services\SSH\SSHConnectionManager;
use Illuminate\Database\QueryException;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\FakeSSHTransport;

function provisioningTransport(): FakeSSHTransport
{
    return (new FakeSSHTransport)
        ->respondTo('echo reachable || echo blocked', "blocked\n", 0)
        ->respondTo('echo open || echo closed', "closed\n", 0)
        ->respondTo('sshd -T', "permitrootlogin without-password\npasswordauthentication no\nkbdinteractiveauthentication no\n", 0)
        ->queueResult("ID=ubuntu\nVERSION_ID=\"24.04\"\n", 0)
        ->queueResult('systemd 255', 0)
        ->queueResult("4\n", 0)
        ->queueResult("Mem:           8000        1000        7000\n", 0)
        ->queueResult("/dev/vda1           40G   10G       30G  25% /\n", 0);
}

function provision(Server $server, FakeSSHTransport $transport): void
{
    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $transport);

    try {
        (new ProvisionServer($server))->handle(app(SSHConnectionManager::class), new OsDetector);
    } finally {
        SSHConnectionManager::createTransportUsing(null);
    }
}

test('phase 1 persists the detected os and hardware', function () {
    $this->freezeTime();
    $server = Server::factory()->create(['host_key_fingerprint' => null]);

    provision($server, provisioningTransport());

    $fresh = $server->fresh();

    expect($fresh->os_name)->toBe('ubuntu')
        ->and($fresh->os_version)->toBe('24.04')
        ->and($fresh->meta)->toBeInAnyKeyOrder(['cpu_cores' => 4, 'memory_mb' => 8000, 'disk_gb' => 40, 'systemd' => 255, 'facts_at' => now()->toIso8601String(), 'caddy_admin' => 'socket', 'caddy_unknown_hosts' => 'closed', 'ssh_hardening' => 'verified']);
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

test('a rocky or almalinux server gets epel and crb turned on before its base packages, even where epel-release came installed but off', function (string $os) {
    $server = Server::factory()->create(['host_key_fingerprint' => null, 'os_name' => $os, 'provisioning_phase' => 1]);
    $transport = new FakeSSHTransport;

    rescue(fn() => provision($server, $transport), report: false);

    $commands = $transport->commands();
    $repositories = array_key_first(array_filter($commands, fn(string $command): bool => str_contains($command, 'dnf install -y epel-release dnf-plugins-core && dnf config-manager --set-enabled epel && crb enable')));
    $base = array_key_first(array_filter($commands, fn(string $command): bool => str_contains($command, "'htop'")));

    expect($repositories)->toBeInt()
        ->and($base)->toBeInt()
        ->and($repositories)->toBeLessThan($base);
})->with(['rocky', 'almalinux']);

test('a server whose own repositories carry every base package is not given epel', function (string $os) {
    $server = Server::factory()->create(['host_key_fingerprint' => null, 'os_name' => $os, 'provisioning_phase' => 1]);
    $transport = new FakeSSHTransport;

    rescue(fn() => provision($server, $transport), report: false);

    expect(implode("\n", $transport->commands()))->not->toContain('epel-release')
        ->and(implode("\n", $transport->commands()))->toContain("'htop'");
})->with(['ubuntu', 'debian', 'fedora']);

test('every implemented phase is recorded', function () {
    $server = Server::factory()->create(['host_key_fingerprint' => null]);

    provision($server, provisioningTransport());

    expect($server->fresh()->provisioning_phase)->toBe(8);
});

test('phase 3 installs asdf as the coresmith user and adds every plugin', function () {
    $server = Server::factory()->create(['host_key_fingerprint' => null]);
    $transport = provisioningTransport();

    provision($server, $transport);

    $commands = implode("\n", $transport->commands());

    expect($commands)->toContain('useradd --system')
        ->and($commands)->toContain('asdf-vm/asdf.git')
        ->and($commands)->toContain('asdf plugin add php')
        ->and($commands)->toContain('asdf plugin add bun')
        ->and($commands)->toContain('sudo -u coresmith');
});

test('a re-run skips already completed phases', function () {
    $server = Server::factory()->create([
        'host_key_fingerprint' => null,
        'os_name' => 'ubuntu',
        'provisioning_phase' => 8,
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
    $transport = (new FakeSSHTransport)->respondTo('echo reachable || echo blocked', "blocked\n", 0)->respondTo('echo open || echo closed', "closed\n", 0);

    provision($server, $transport);

    $commands = implode("\n", $transport->commands());

    expect($commands)->not->toContain('/etc/os-release')
        ->and($commands)->toContain('apt-get update');
});

test('every phase is logged as it starts and finishes, and completed phases say they were skipped', function () {
    $server = Server::factory()->create(['host_key_fingerprint' => null, 'os_name' => 'ubuntu', 'provisioning_phase' => 1]);
    $transport = (new FakeSSHTransport)->respondTo('echo reachable || echo blocked', "blocked\n", 0)->respondTo('echo open || echo closed', "closed\n", 0);

    provision($server, $transport);

    expect($server->fresh()->provisioning_log)
        ->toContain('phase 1 of 8, Operating system: already done, skipped')
        ->toContain('phase 2 of 8, Base system: started')
        ->toContain('phase 2 of 8, Base system: done');
});

test('a failed phase stores the reason and the end of the command output', function () {
    $server = Server::factory()->create(['host_key_fingerprint' => null, 'os_name' => 'ubuntu', 'provisioning_phase' => 1]);
    $transport = (new FakeSSHTransport)->respondTo('apt-get update', "Reading package lists...\nE: Could not get lock /var/lib/dpkg/lock-frontend. It is held by process 2231 (unattended-upgr)\n", 100);

    expect(fn() => provision($server, $transport))->toThrow(SSHCommandFailedException::class);

    $fresh = $server->fresh();

    expect($fresh->status)->toBe(ServerStatus::Failed)
        ->and($fresh->provisioning_error)->toStartWith('Base system failed. The command exited with code 100.')
        ->and($fresh->provisioning_error)->toContain('Could not get lock /var/lib/dpkg/lock-frontend')
        ->and($fresh->provisioning_log)->toContain('phase 2 of 8, Base system: failed');
});

test('a failed phase redacts secrets from the command output before storing it', function () {
    $server = Server::factory()->create(['host_key_fingerprint' => null, 'os_name' => 'ubuntu', 'provisioning_phase' => 1]);
    $transport = (new FakeSSHTransport)->respondTo(
        'apt-get update',
        "Authorization: Bearer sk-abcdef1234567890\nfetching https://token123secret@example.test/repo.git\nE: Could not get lock\n",
        100,
    );

    expect(fn() => provision($server, $transport))->toThrow(SSHCommandFailedException::class);

    $fresh = $server->fresh();

    expect($fresh->provisioning_error)->toContain('Bearer [REDACTED]')
        ->and($fresh->provisioning_error)->toContain('https://[REDACTED]@example.test/repo.git')
        ->and($fresh->provisioning_error)->not->toContain('sk-abcdef1234567890')
        ->and($fresh->provisioning_error)->not->toContain('token123secret')
        ->and($fresh->provisioning_log)->toContain('Bearer [REDACTED]')
        ->and($fresh->provisioning_log)->not->toContain('sk-abcdef1234567890')
        ->and($fresh->provisioning_log)->toContain('exit code 100');
});

test('starting over runs every phase again from the first', function () {
    $server = Server::factory()->create(['host_key_fingerprint' => null, 'provisioning_phase' => 5, 'provisioning_error' => 'Caddy failed.']);

    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => provisioningTransport());

    try {
        (new ProvisionServer($server, fromScratch: true))->handle(app(SSHConnectionManager::class), new OsDetector);
    } finally {
        SSHConnectionManager::createTransportUsing(null);
    }

    expect($server->fresh()->provisioning_log)->toContain('phase 1 of 8, Operating system: started')
        ->and($server->fresh()->provisioning_error)->toBeNull();
});

test('a failure marks the server failed and preserves the completed phase', function () {
    $server = Server::factory()->create(['host_key_fingerprint' => null]);
    $transport = (new FakeSSHTransport)
        ->respondTo('lscpu', '', 0)
        ->queueResult("ID=ubuntu\nVERSION_ID=\"24.04\"\n", 0)
        ->queueResult('systemd 255', 0)
        ->queueResult("4\n", 0)
        ->queueResult("Mem:           8000        1000        7000\n", 0)
        ->queueResult("/dev/vda1           40G   10G       30G  25% /\n", 0)
        ->queueResult('E: Could not get lock', 100);

    expect(fn() => provision($server, $transport))->toThrow(SSHCommandFailedException::class);

    $fresh = $server->fresh();

    expect($fresh->status)->toBe(ServerStatus::Failed)
        ->and($fresh->provisioning_phase)->toBe(1)
        ->and($fresh->provisioning_log)->toContain('exit code 100');
});

test('a job that dies outside handle leaves the server failed and recoverable', function () {
    $server = Server::factory()->create(['status' => ServerStatus::Provisioning, 'provisioning_phase' => 3, 'host_key_fingerprint' => null]);

    (new ProvisionServer($server))->failed(new MaxAttemptsExceededException('Job has timed out.'));

    $fresh = $server->fresh();

    expect($fresh->status)->toBe(ServerStatus::Failed)
        ->and($fresh->provisioning_phase)->toBe(3)
        ->and($fresh->provisioning_error)->toContain('Job has timed out.');
});

test('an unsupported distribution fails provisioning cleanly', function () {
    $server = Server::factory()->create(['host_key_fingerprint' => null]);
    $transport = (new FakeSSHTransport)->queueResult("ID=gentoo\nVERSION_ID=\"2\"\n", 0);

    expect(fn() => provision($server, $transport))->toThrow(UnsupportedOperatingSystemException::class);

    expect($server->fresh()->status)->toBe(ServerStatus::Failed)
        ->and($server->fresh()->provisioning_phase)->toBeNull();
});

test('phase 1 also records the processor, the system name and the memory installed', function () {
    $server = Server::factory()->create(['host_key_fingerprint' => null]);
    $transport = provisioningTransport()->respondTo('lscpu', "cpu_model=AMD EPYC-Milan Processor\ncpu_cores=2\nos=Ubuntu 24.04.3 LTS\ninstalled_mb=4096\n");

    provision($server, $transport);

    expect($server->fresh()->meta)->toMatchArray([
        'cpu_cores' => 4,
        'memory_mb' => 8000,
        'cpu_model' => 'AMD EPYC-Milan Processor',
        'cpu_physical_cores' => 2,
        'os_pretty' => 'Ubuntu 24.04.3 LTS',
        'memory_installed_mb' => 4096,
    ]);
});

test('on the machine Coresmith runs on, runtimes and app folders belong to a user of their own, so nothing borrows Coresmith\'s user', function () {
    $server = Server::factory()->create(['host_key_fingerprint' => null, 'runs_coresmith' => true]);
    $transport = provisioningTransport()->respondTo('caddy adapt', (string) file_get_contents(base_path('tests/Fixtures/Caddy/adapted-internal.json')));

    provision($server, $transport);

    $commands = implode("\n", $transport->commands());

    expect($commands)->toContain('useradd --system --create-home --home-dir /home/coresmith-runtime --shell /bin/bash coresmith-runtime')
        ->and($commands)->toContain('chown -R coresmith-runtime:coresmith-runtime /opt/asdf')
        ->and($commands)->toContain('chown root:coresmith-runtime /var/coresmith/apps && chmod 751 /var/coresmith/apps')
        ->and($commands)->not->toContain('--home-dir /home/coresmith --shell')
        ->and($commands)->not->toContain('chown root:coresmith /var')
        ->and($commands)->not->toContain('sudo -u coresmith env ');
});

test('phase 4 creates the coresmith directory tree with locked down secret dirs', function () {
    $server = Server::factory()->create(['host_key_fingerprint' => null]);
    $transport = provisioningTransport();

    provision($server, $transport);

    $commands = implode("\n", $transport->commands());

    expect($commands)->toContain('mkdir -p /var/coresmith/apps')
        ->and($commands)->toContain('chown root:coresmith /var/coresmith/apps && chmod 751 /var/coresmith/apps')
        ->and($commands)->toContain('chown root:coresmith /var/coresmith/logs && chmod 750 /var/coresmith/logs')
        ->and($commands)->toContain('chmod 700 /etc/coresmith/blueprints')
        ->and($commands)->toContain('mkdir -p /etc/coresmith/env')
        ->and($commands)->toContain('chown root:root /etc/coresmith/env')
        ->and($commands)->toContain('chmod 700 /etc/coresmith/env')
        ->and($commands)->toContain('mkdir -p ' . DeployKeyManager::KEY_DIR . ' && chown root:root ' . DeployKeyManager::KEY_DIR . ' && chmod 700 ' . DeployKeyManager::KEY_DIR);
});

test('phase 5 installs caddy and verifies the admin api answers on its private socket', function () {
    $server = Server::factory()->create(['host_key_fingerprint' => null]);
    $transport = provisioningTransport();

    provision($server, $transport);

    $commands = implode("\n", $transport->commands());

    expect($commands)->toContain("apt-get install -y --no-install-recommends 'caddy'")
        ->and($commands)->toContain('systemctl enable caddy')
        ->and($commands)->toContain("--unix-socket /run/caddy-admin/admin.sock 'http://localhost/config/'");
});

test('phase 5 uploads the caddy config apps are added to, and starts caddy from it', function () {
    $server = Server::factory()->create(['host_key_fingerprint' => null]);
    $transport = provisioningTransport();

    provision($server, $transport);

    $uploads = $transport->uploads();
    $config = json_decode($uploads[CaddyInstaller::CONFIG_FILE] ?? '', true);

    expect($config['apps']['http']['servers'][CaddyInstaller::SERVER]['routes'])->toBe([CaddyInstaller::unknownHostsRoute()])
        ->and($uploads[CaddyInstaller::CONFIG_FILE])->not->toContain('/var/coresmith/apps')
        ->and($uploads)->toHaveKey(CaddyInstaller::SERVICE_DROP_IN);
});

test('provisioning halts if the caddy admin api answers from outside the box', function () {
    $server = Server::factory()->create(['host_key_fingerprint' => null]);
    $transport = provisioningTransport()->respondTo('echo reachable || echo blocked', "reachable\n", 0);

    expect(fn() => provision($server, $transport))->toThrow(ProvisioningVerificationException::class);

    expect($server->fresh()->status)->toBe(ServerStatus::Failed);
});

test('a fully provisioned server becomes active and announces itself', function () {
    Event::fake([ServerProvisioned::class]);
    $server = Server::factory()->create(['host_key_fingerprint' => null]);

    provision($server, provisioningTransport());

    expect($server->fresh()->status)->toBe(ServerStatus::Active)
        ->and($server->fresh()->last_connected_at)->not->toBeNull();

    Event::assertDispatched(ServerProvisioned::class);
});

test('a mail that cannot be queued does not undo a finished provision', function () {
    Exceptions::fake();
    $server = Server::factory()->create(['host_key_fingerprint' => null]);
    $server->team->members()->attach(User::factory()->create(), ['role' => TeamRole::Member->value]);
    Notification::shouldReceive('send')->once()->andThrow(new RuntimeException('Queue is down'));

    provision($server, provisioningTransport());

    $fresh = $server->fresh();

    expect($fresh->status)->toBe(ServerStatus::Active)
        ->and($fresh->provisioning_phase)->toBe(8)
        ->and($fresh->provisioning_error)->toBeNull()
        ->and(Activity::query()->where('description', 'provisioning_failed')->exists())->toBeFalse();
    Exceptions::assertReportedCount(1);
    Exceptions::assertReported(fn(RuntimeException $e): bool => $e->getMessage() === 'Queue is down');
});

test('a mail that cannot be queued does not hide why provisioning stopped', function () {
    Exceptions::fake();
    $server = Server::factory()->create(['host_key_fingerprint' => null]);
    $server->team->members()->attach(User::factory()->create(), ['role' => TeamRole::Member->value]);
    Notification::shouldReceive('send')->once()->andThrow(new RuntimeException('Queue is down'));
    $transport = provisioningTransport()->respondTo('apt-get install', 'E: Unable to locate package', 100);

    expect(fn() => provision($server, $transport))->toThrow(SSHCommandFailedException::class);

    expect($server->fresh()->status)->toBe(ServerStatus::Failed)
        ->and(Activity::query()->where('description', 'provisioning_failed')->count())->toBe(1);
    Exceptions::assertReportedCount(1);
    Exceptions::assertReported(fn(RuntimeException $e): bool => $e->getMessage() === 'Queue is down');
});

test('phase 6 opens only ssh, http and https', function () {
    $server = Server::factory()->create(['host_key_fingerprint' => null, 'ssh_port' => 2222]);
    $transport = provisioningTransport();

    provision($server, $transport);

    $commands = implode("\n", $transport->commands());

    expect($commands)->toContain('ufw allow 2222/tcp')
        ->and($commands)->toContain('ufw allow 80/tcp')
        ->and($commands)->toContain('ufw allow 443/tcp')
        ->and($commands)->toContain('default deny incoming')
        ->and($commands)->not->toContain('ufw allow 22/tcp');
});

test("phase 6 on Coresmith's own machine keeps the firewall it already has, opening only the web ports", function () {
    $server = Server::factory()->create(['host_key_fingerprint' => null, 'runs_coresmith' => true, 'meta' => ['cpu_cores' => 4]]);
    $transport = provisioningTransport()->respondTo('caddy adapt', (string) file_get_contents(base_path('tests/Fixtures/Caddy/adapted-internal.json')));

    provision($server, $transport);

    $commands = implode("\n", $transport->commands());

    expect($commands)->toContain('ufw allow 80/tcp')
        ->and($commands)->toContain('ufw allow 443/tcp')
        ->and($commands)->not->toContain('ufw allow 22/tcp')
        ->and($commands)->not->toContain('default deny incoming')
        ->and($commands)->not->toContain('ufw --force enable')
        ->and($server->fresh()->meta['firewall_ssh'] ?? null)->toBe('kept');
});

test("on Coresmith's own machine, provisioning leaves the owner's SSH settings and timezone alone", function () {
    $server = Server::factory()->create(['host_key_fingerprint' => null, 'runs_coresmith' => true]);
    $transport = provisioningTransport()->respondTo('caddy adapt', (string) file_get_contents(base_path('tests/Fixtures/Caddy/adapted-internal.json')));

    provision($server, $transport);

    $commands = implode("\n", $transport->commands());

    expect($transport->uploads())->not->toHaveKey(SecurityHardening::SSHD_CONFIG)
        ->and($commands)->not->toContain('timedatectl set-timezone')
        ->and($server->fresh()->meta['ssh_hardening'] ?? null)->toBe('owner');
});

test("phase 6 on Coresmith's own machine turns a firewall that was off on with SSH open, so nobody is locked out", function () {
    $server = Server::factory()->create(['host_key_fingerprint' => null, 'runs_coresmith' => true]);
    $transport = provisioningTransport()
        ->respondTo('caddy adapt', (string) file_get_contents(base_path('tests/Fixtures/Caddy/adapted-internal.json')))
        ->respondTo("ufw status | grep -q '^Status: active'", '', 1);

    provision($server, $transport);

    $commands = implode("\n", $transport->commands());

    expect($commands)->toContain('ufw allow 22/tcp')
        ->and($commands)->toContain('default deny incoming')
        ->and($commands)->toContain('ufw --force enable')
        ->and($server->fresh()->meta)->not->toHaveKey('firewall_ssh');
});

test('phase 7 hardens ssh, installs fail2ban and applies sysctls', function () {
    $server = Server::factory()->create(['host_key_fingerprint' => null]);
    $transport = provisioningTransport();

    provision($server, $transport);

    $commands = implode("\n", $transport->commands());
    $uploads = $transport->uploads();

    expect($uploads['/etc/ssh/sshd_config.d/00-coresmith.conf'])->toContain('PasswordAuthentication no')
        ->and($uploads)->not->toHaveKey('/etc/ssh/sshd_config.d/99-coresmith.conf')
        ->and($uploads['/etc/sysctl.d/99-coresmith-hardening.conf'])->toContain('kernel.kptr_restrict = 2')
        ->and($uploads['/etc/fail2ban/jail.d/coresmith-ssh.conf'])->toContain('maxretry = 5')
        ->and($commands)->toContain('rm -f /etc/ssh/sshd_config.d/99-coresmith.conf')
        ->and($commands)->toContain('sshd -T')
        ->and($server->fresh()->sshHardened())->toBeTrue()
        ->and($commands)->toContain('sysctl --system')
        ->and($commands)->toContain('timedatectl set-timezone UTC');
});

test('a failure inside handle is recorded once, not twice when the queue also calls failed', function () {
    $server = Server::factory()->create(['host_key_fingerprint' => null, 'os_name' => 'ubuntu', 'provisioning_phase' => 1]);
    $transport = (new FakeSSHTransport)->respondTo('apt-get update', "E: Could not get lock\n", 100);

    $job = new ProvisionServer($server);

    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $transport);

    try {
        $job->handle(app(SSHConnectionManager::class), new OsDetector);
    } catch (SSHCommandFailedException $e) {
        $job->failed($e);
    } finally {
        SSHConnectionManager::createTransportUsing(null);
    }

    expect(substr_count((string) $server->fresh()->provisioning_log, 'phase 2 of 8, Base system: failed'))->toBe(1);
});

test('a provision that stops says which phase, once', function () {
    Event::fake([ServerProvisioningFailed::class]);
    $server = Server::factory()->create(['host_key_fingerprint' => null]);
    $transport = provisioningTransport()->respondTo('apt-get install', 'E: Unable to locate package', 100);

    expect(fn() => provision($server, $transport))->toThrow(SSHCommandFailedException::class);

    (new ProvisionServer($server))->failed(new MaxAttemptsExceededException('Job has timed out.'));

    Event::assertDispatchedTimes(ServerProvisioningFailed::class, 1);
    Event::assertDispatched(ServerProvisioningFailed::class, fn(ServerProvisioningFailed $event): bool => $event->server->is($server) && $event->phase === 2 && $event->phaseName === 'Base system');

    $rows = Activity::query()->where('description', 'provisioning_failed')->get();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->subject_id)->toBe($server->id)
        ->and($rows[0]->properties->toArray())->toBeInAnyKeyOrder(['phase' => 2, 'name' => 'Base system']);
});

test('a job that dies outside handle says which phase it stopped in', function () {
    Event::fake([ServerProvisioningFailed::class]);
    $server = Server::factory()->create(['status' => ServerStatus::Provisioning, 'provisioning_phase' => 3, 'host_key_fingerprint' => null]);

    (new ProvisionServer($server))->failed(new MaxAttemptsExceededException('Job has timed out.'));

    Event::assertDispatchedTimes(ServerProvisioningFailed::class, 1);
    Event::assertDispatched(ServerProvisioningFailed::class, fn(ServerProvisioningFailed $event): bool => $event->phase === 4 && $event->phaseName === 'Directories');
    expect(Activity::query()->where('description', 'provisioning_failed')->sole()->properties->toArray())->toBeInAnyKeyOrder(['phase' => 4, 'name' => 'Directories']);
});

test('installing the base packages gets twenty minutes, not the ten every other command gets', function () {
    $server = Server::factory()->create(['host_key_fingerprint' => null, 'os_name' => 'ubuntu', 'provisioning_phase' => 1]);
    $transport = provisioningTransport();

    rescue(fn() => provision($server, $transport), report: false);

    expect($transport->budgetFor('apt-get update'))->toBe(SSHTransport::PACKAGE_SECONDS)
        ->and(SSHTransport::PACKAGE_SECONDS)->toBe(1200);
});

test('provisioning starts once when two callers saw the server waiting', function () {
    Queue::fake([ProvisionServer::class]);
    $server = Server::factory()->create(['status' => ServerStatus::Pending]);
    $stale = Server::query()->findOrFail($server->getKey());

    ProvisionServer::begin($server);
    ProvisionServer::begin($stale);

    expect($server->fresh()->status)->toBe(ServerStatus::Provisioning)
        ->and($server->status)->toBe(ServerStatus::Provisioning)
        ->and($server->isDirty('status'))->toBeFalse();
    Queue::assertPushed(ProvisionServer::class, 1);
    Queue::assertPushed(ProvisionServer::class, fn(ProvisionServer $job): bool => (fn() => $this->server)->call($job)->is($server));
});

test('a server that is not waiting to be provisioned is left alone', function (ServerStatus $status) {
    Queue::fake([ProvisionServer::class]);
    $server = Server::factory()->create(['status' => $status]);

    ProvisionServer::begin($server);

    expect($server->fresh()->status)->toBe($status);
    Queue::assertNotPushed(ProvisionServer::class);
})->with([ServerStatus::Provisioning, ServerStatus::Active, ServerStatus::Failed, ServerStatus::Creating]);

test('a detached server is not provisioned', function () {
    Queue::fake([ProvisionServer::class]);
    $server = Server::factory()->create(['status' => ServerStatus::Pending]);
    $server->delete();

    ProvisionServer::begin($server);

    expect(Server::withTrashed()->findOrFail($server->getKey())->status)->toBe(ServerStatus::Pending);
    Queue::assertNotPushed(ProvisionServer::class);
});

test('a disconnected server can be provisioned', function () {
    Queue::fake([ProvisionServer::class]);
    $server = Server::factory()->create(['status' => ServerStatus::Disconnected]);

    ProvisionServer::begin($server);

    expect($server->fresh()->status)->toBe(ServerStatus::Provisioning);
    Queue::assertPushed(ProvisionServer::class, 1);
});

test('a provisioning job that cannot be queued puts the server back', function () {
    config([
        'database.connections.unqueueable' => ['driver' => 'sqlite', 'database' => ':memory:'],
        'queue.connections.unqueueable' => ['driver' => 'database', 'connection' => 'unqueueable', 'table' => 'no_such_jobs', 'queue' => 'provisioning'],
        'queue.default' => 'unqueueable',
    ]);
    $server = Server::factory()->create(['status' => ServerStatus::Pending]);

    expect(fn() => ProvisionServer::begin($server))->toThrow(QueryException::class);

    expect($server->fresh()->status)->toBe(ServerStatus::Pending)
        ->and($server->status)->toBe(ServerStatus::Pending)
        ->and($server->isDirty('status'))->toBeFalse();
});
