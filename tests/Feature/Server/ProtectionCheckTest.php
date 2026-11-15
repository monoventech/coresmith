<?php

declare(strict_types=1);

use App\Enums\ServerStatus;
use App\Jobs\CheckProtections;
use App\Models\Server;
use App\Services\Server\ProtectionCheck;
use App\Services\SSH\SSHConnectionManager;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Process\Process;
use Tests\Support\FakeSSHTransport;
use Tests\Support\ProtectionReading;

afterEach(function () {
    SSHConnectionManager::createTransportUsing(null);
});

function protectionServer(string $os = 'ubuntu', bool $ownMachine = false): Server
{
    return Server::factory()->create(['os_name' => $os, 'runs_coresmith' => $ownMachine, 'host_key_fingerprint' => null]);
}

/**
 * @param  array<string, string>  $fakes
 */
function protectionWebCheck(array $fakes): string
{
    $bin = sys_get_temp_dir() . '/protection-web-' . bin2hex(random_bytes(4));
    mkdir($bin);

    foreach ($fakes as $command => $body) {
        file_put_contents("{$bin}/{$command}", "#!/bin/sh\n{$body}\n");
        chmod("{$bin}/{$command}", 0755);
    }

    $process = new Process(['/bin/sh', '-c', ProtectionCheck::HTTP3_CHECK], null, ['PATH' => $bin . ':/usr/bin:/bin']);
    $process->run();

    array_map(unlink(...), glob($bin . '/*') ?: []);
    rmdir($bin);

    return trim($process->getOutput());
}

test('a server with every protection in place reads as on', function () {
    expect(app(ProtectionCheck::class)->parse(protectionServer(), ProtectionReading::output()))->toBe([
        'ssh' => 'on',
        'fail2ban' => 'on',
        'kernel' => 'on',
        'kernel_off' => [],
        'patches' => 'on',
        'controls' => 'on',
        'http3' => 'on',
    ]);
});

test('the sign-in blocker reads as off when it stopped or lost its SSH jail', function (array $lines) {
    expect(app(ProtectionCheck::class)->parse(protectionServer(), ProtectionReading::output(['fail2ban' => $lines]))['fail2ban'])->toBe('off');
})->with([
    'stopped' => [['inactive', 'no-jail']],
    'no jail' => [['active', 'no-jail']],
    'not installed' => [['', 'no-jail']],
]);

test('a kernel setting that drifted is named, and one the kernel lacks is not held against the server', function () {
    $kernel = ProtectionReading::kernel();
    $drifted = array_map(fn(string $line): string => match (true) {
        str_starts_with($line, 'kernel.dmesg_restrict') => 'kernel.dmesg_restrict = 0',
        str_starts_with($line, 'net.ipv6.') => '',
        default => $line,
    }, $kernel);

    $reading = app(ProtectionCheck::class)->parse(protectionServer(), ProtectionReading::output(['kernel' => $drifted]));

    expect($reading['kernel'])->toBe('off')
        ->and($reading['kernel_off'])->toBe(['kernel.dmesg_restrict']);
});

test('security updates on Debian and Ubuntu count only when the daily run installs them', function (array $lines, string $state) {
    expect(app(ProtectionCheck::class)->parse(protectionServer('debian'), ProtectionReading::output(['patches' => $lines]))['patches'])->toBe($state);
})->with([
    'on' => [["UNATTENDED='1'", 'installed', 'enabled'], 'on'],
    'turned off in apt' => [["UNATTENDED='0'", 'installed', 'enabled'], 'off'],
    'never set' => [['installed', 'enabled'], 'off'],
    'periodic runs turned off' => [["UNATTENDED='1'", "PERIODIC='0'", 'installed', 'enabled'], 'off'],
    'not installed' => [["UNATTENDED='1'", 'missing', 'enabled'], 'off'],
    'daily run disabled' => [["UNATTENDED='1'", 'installed', 'disabled'], 'off'],
]);

test('security updates on the Red Hat family count only when they are installed, not just downloaded', function (array $lines, string $state) {
    expect(app(ProtectionCheck::class)->parse(protectionServer('rocky'), ProtectionReading::output(['patches' => $lines]))['patches'])->toBe($state);
})->with([
    'the install timer' => [['dnf-automatic-install.timer enabled', 'dnf-automatic.timer disabled', 'dnf5-automatic.timer missing'], 'on'],
    'the plain timer told to apply' => [['dnf-automatic-install.timer disabled', 'dnf-automatic.timer enabled', 'dnf5-automatic.timer missing', 'apply_updates = yes'], 'on'],
    'dnf5 told to apply' => [['dnf-automatic-install.timer not-found', 'dnf-automatic.timer alias', 'dnf5-automatic.timer enabled', 'apply_updates=True'], 'on'],
    'dnf5 as it comes' => [['dnf-automatic-install.timer not-found', 'dnf-automatic.timer alias', 'dnf5-automatic.timer enabled', 'apply_updates = no'], 'off'],
    'the plain timer that only downloads' => [['dnf-automatic-install.timer disabled', 'dnf-automatic.timer enabled', 'dnf5-automatic.timer missing', 'apply_updates = no'], 'off'],
    'no timer on' => [['dnf-automatic-install.timer disabled', 'dnf-automatic.timer disabled', 'dnf5-automatic.timer missing', 'apply_updates = yes'], 'off'],
]);

test('password logins that came back read as off', function () {
    $reading = app(ProtectionCheck::class)->parse(protectionServer(), ProtectionReading::output(['ssh' => ['passwordauthentication yes', 'kbdinteractiveauthentication no', 'permitrootlogin without-password']]));

    expect($reading['ssh'])->toBe('off');
});

test('Coresmith\'s own machine leaves SSH to its owner, so the check reports nothing for it', function () {
    expect(app(ProtectionCheck::class)->parse(protectionServer(ownMachine: true), ProtectionReading::output())['ssh'])->toBeNull()
        ->and(app(ProtectionCheck::class)->script(protectionServer(ownMachine: true)))->not->toContain('sshd -T');
});

test('the routing controls read as off while anything can still reach them over TCP', function (array $lines) {
    expect(app(ProtectionCheck::class)->parse(protectionServer(), ProtectionReading::output(['controls' => $lines]))['controls'])->toBe('off');
})->with([
    'still on tcp' => [['no-socket', 'tcp']],
    'socket and tcp' => [['socket', 'tcp']],
]);

test('HTTP/3 reads as off only while a running firewall blocks UDP 443', function (array $lines, string $state) {
    expect(app(ProtectionCheck::class)->parse(protectionServer(), ProtectionReading::output(['web' => $lines]))['http3'])->toBe($state);
})->with([
    'blocked' => [['udp-closed'], 'off'],
    'let through' => [['udp-open'], 'on'],
]);

test('the firewall check tells UDP 443 apart on ufw and firewalld, and counts no firewall as open', function (array $fakes, string $expected) {
    expect(protectionWebCheck($fakes))->toBe($expected);
})->with([
    'ufw with udp 443' => [['ufw' => "printf 'Status: active\\n\\nTo Action From\\n443/tcp ALLOW Anywhere\\n443/udp ALLOW Anywhere\\n'"], 'udp-open'],
    'ufw with 443 for both' => [['ufw' => "printf 'Status: active\\n\\n443 ALLOW Anywhere\\n'"], 'udp-open'],
    'ufw with tcp only' => [['ufw' => "printf 'Status: active\\n\\n80/tcp ALLOW Anywhere\\n443/tcp ALLOW Anywhere\\n'"], 'udp-closed'],
    'ufw switched off' => [['ufw' => "printf 'Status: inactive\\n'"], 'udp-open'],
    'firewalld with the port' => [['firewall-cmd' => '[ "$1" = --list-ports ] && echo "443/udp 8080/tcp"; exit 0'], 'udp-open'],
    'firewalld with the service' => [['firewall-cmd' => '[ "$1" = --list-services ] && echo "http https http3"; exit 0'], 'udp-open'],
    'firewalld without it' => [['firewall-cmd' => '[ "$1" = --list-services ] && echo "http https ssh"; exit 0'], 'udp-closed'],
    'no firewall' => [[], 'udp-open'],
]);

test('the script only reads, and asks each family for its own update tool', function () {
    $check = app(ProtectionCheck::class);

    expect($check->script(protectionServer('debian')))->toContain('apt-config shell UNATTENDED APT::Periodic::Unattended-Upgrade')->not->toContain('dnf')
        ->and($check->script(protectionServer('rocky')))->toContain('dnf-automatic-install.timer')->not->toContain('apt-config')
        ->and($check->script(protectionServer()))->not->toMatch('/systemctl (start|stop|restart|enable|disable|reload)|sysctl -w|sysctl --system|> \//');
});

test('the hourly check records what it read and when', function () {
    $this->travelTo(now()->startOfMinute());
    $server = protectionServer();
    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => (new FakeSSHTransport)->respondTo('== fail2ban', ProtectionReading::output(['fail2ban' => ['inactive', 'no-jail']])));

    app()->call([new CheckProtections($server), 'handle']);

    expect(((array) $server->fresh()->meta)['protections'])->toEqual([
        'ssh' => 'on',
        'fail2ban' => 'off',
        'kernel' => 'on',
        'kernel_off' => [],
        'patches' => 'on',
        'controls' => 'on',
        'http3' => 'on',
        'checked_at' => now()->toIso8601String(),
    ]);
});

test('a check that could not read the server keeps the last reading', function () {
    $server = protectionServer();
    $server->forceFill(['meta' => ['protections' => ['fail2ban' => 'on', 'checked_at' => '2026-11-13T09:00:00+00:00']]])->save();
    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => (new FakeSSHTransport)->refuseConnections());

    app()->call([new CheckProtections($server), 'handle']);

    expect(((array) $server->fresh()->meta)['protections'])->toEqual(['fail2ban' => 'on', 'checked_at' => '2026-11-13T09:00:00+00:00']);
});

test('every hour, each active and reachable server is checked', function () {
    Queue::fake();
    $due = Server::factory()->create(['status' => ServerStatus::Active]);
    Server::factory()->create(['status' => ServerStatus::Active, 'unreachable_since' => now()]);
    Server::factory()->create(['status' => ServerStatus::Active, 'pending_host_key_fingerprint' => 'SHA256:changed']);
    Server::factory()->create(['status' => ServerStatus::Pending]);

    $event = collect(app(Schedule::class)->events())->first(fn($event): bool => $event->description === 'coresmith:check-protections');

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('30 * * * *');

    $event->run(app());

    Queue::assertPushed(CheckProtections::class, 1);
    Queue::assertPushed(CheckProtections::class, fn(CheckProtections $job): bool => $job->server->is($due));
});
