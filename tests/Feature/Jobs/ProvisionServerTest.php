<?php

declare(strict_types=1);

use App\Enums\ServerStatus;
use App\Events\ServerProvisioned;
use App\Exceptions\ProvisioningVerificationException;
use App\Exceptions\SSHCommandFailedException;
use App\Exceptions\UnsupportedOperatingSystemException;
use App\Jobs\ProvisionServer;
use App\Models\Server;
use App\Services\Server\OsDetector;
use App\Services\SSH\SSHConnectionManager;
use Illuminate\Support\Facades\Event;
use Tests\Support\FakeSSHTransport;

function provisioningTransport(): FakeSSHTransport
{
    return (new FakeSSHTransport)
        ->respondTo('echo reachable || echo blocked', "blocked\n", 0)
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
    $transport = (new FakeSSHTransport)->respondTo('echo reachable || echo blocked', "blocked\n", 0);

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

    expect(fn() => provision($server, $transport))->toThrow(SSHCommandFailedException::class);

    $fresh = $server->fresh();

    expect($fresh->status)->toBe(ServerStatus::Failed)
        ->and($fresh->provisioning_phase)->toBe(1)
        ->and($fresh->provisioning_log)->toContain('exit code 100');
});

test('an unsupported distribution fails provisioning cleanly', function () {
    $server = Server::factory()->create(['host_key_fingerprint' => null]);
    $transport = (new FakeSSHTransport)->queueResult("ID=gentoo\nVERSION_ID=\"2\"\n", 0);

    expect(fn() => provision($server, $transport))->toThrow(UnsupportedOperatingSystemException::class);

    expect($server->fresh()->status)->toBe(ServerStatus::Failed)
        ->and($server->fresh()->provisioning_phase)->toBeNull();
});

test('phase 4 creates the coresmith directory tree with locked down secret dirs', function () {
    $server = Server::factory()->create(['host_key_fingerprint' => null]);
    $transport = provisioningTransport();

    provision($server, $transport);

    $commands = implode("\n", $transport->commands());

    expect($commands)->toContain('mkdir -p /var/coresmith/apps')
        ->and($commands)->toContain('chmod 750 /var/coresmith/apps')
        ->and($commands)->toContain('mkdir -p /etc/coresmith/env')
        ->and($commands)->toContain('chown root:root /etc/coresmith/env')
        ->and($commands)->toContain('chmod 700 /etc/coresmith/env')
        ->and($commands)->toContain('chmod 700 /etc/coresmith/deploy-keys');
});

test('phase 5 installs caddy and verifies the admin api responds on localhost', function () {
    $server = Server::factory()->create(['host_key_fingerprint' => null]);
    $transport = provisioningTransport();

    provision($server, $transport);

    $commands = implode("\n", $transport->commands());

    expect($commands)->toContain("apt-get install -y --no-install-recommends 'caddy'")
        ->and($commands)->toContain('systemctl enable caddy')
        ->and($commands)->toContain('http://localhost:2019/config/');
});

test('phase 5 uploads a bootstrap caddyfile that never grows with apps', function () {
    $server = Server::factory()->create(['host_key_fingerprint' => null]);
    $transport = provisioningTransport();

    provision($server, $transport);

    $uploads = $transport->uploads();

    expect($uploads)->toHaveKey('/etc/caddy/Caddyfile')
        ->and($uploads['/etc/caddy/Caddyfile'])->toContain('respond 444')
        ->and($uploads['/etc/caddy/Caddyfile'])->not->toContain('/var/coresmith/apps');
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

test('phase 7 hardens ssh, installs fail2ban and applies sysctls', function () {
    $server = Server::factory()->create(['host_key_fingerprint' => null]);
    $transport = provisioningTransport();

    provision($server, $transport);

    $commands = implode("\n", $transport->commands());
    $uploads = $transport->uploads();

    expect($uploads['/etc/ssh/sshd_config.d/99-coresmith.conf'])->toContain('PasswordAuthentication no')
        ->and($uploads['/etc/sysctl.d/99-coresmith-hardening.conf'])->toContain('kernel.kptr_restrict = 2')
        ->and($uploads['/etc/fail2ban/jail.d/coresmith-ssh.conf'])->toContain('maxretry = 5')
        ->and($commands)->toContain('sshd -t')
        ->and($commands)->toContain('sysctl --system')
        ->and($commands)->toContain('timedatectl set-timezone UTC');
});
