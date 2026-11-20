<?php

declare(strict_types=1);

use App\Exceptions\UnsupportedOperatingSystemException;
use App\Models\Server;
use App\Services\Server\FirewallConfigurator;
use App\Services\Server\FirewalldFirewallManager;
use App\Services\Server\FirewallManagerFactory;
use App\Services\Server\SecurityHardening;
use App\Services\Server\UfwFirewallManager;

test('the factory resolves the firewall per distribution', function (string $os, string $expected) {
    expect(FirewallManagerFactory::for(Server::factory()->make(['os_name' => $os])))->toBeInstanceOf($expected);
})->with([
    ['ubuntu', UfwFirewallManager::class],
    ['debian', UfwFirewallManager::class],
    ['rocky', FirewalldFirewallManager::class],
    ['fedora', FirewalldFirewallManager::class],
    ['amzn', FirewalldFirewallManager::class],
]);

test('an unsupported distribution has no firewall manager', function () {
    FirewallManagerFactory::for(Server::factory()->make(['os_name' => 'windows']));
})->throws(UnsupportedOperatingSystemException::class);

test('ufw builds the expected rules', function () {
    $ufw = new UfwFirewallManager;

    expect($ufw->allow(443))->toBe('ufw allow 443/tcp')
        ->and($ufw->deny(6379))->toBe('ufw deny 6379/tcp')
        ->and($ufw->allowFrom('203.0.113.5', 6379))->toBe('ufw allow from 203.0.113.5 to any port 6379')
        ->and($ufw->denyIncomingByDefault())->toContain('default deny incoming')
        ->and($ufw->enable())->toBe('ufw --force enable');
});

test('firewalld builds the expected rules', function () {
    $firewalld = new FirewalldFirewallManager;

    expect($firewalld->allow(443))->toContain('--add-port=443/tcp')
        ->and($firewalld->allow(443))->toContain('--reload')
        ->and($firewalld->allowFrom('203.0.113.5', 6379))->toContain('203.0.113.5');
});

test('firewalld is running before it is given the default zone, which it only takes as a command of its own', function () {
    $command = (new FirewalldFirewallManager)->denyIncomingByDefault();

    expect($command)->toStartWith('systemctl enable --now firewalld && ')
        ->and($command)->toContain('firewall-cmd --set-default-zone=public')
        ->and($command)->not->toContain('--permanent');
});

test('a port below the valid range is rejected', function () {
    (new UfwFirewallManager)->allow(0);
})->throws(InvalidArgumentException::class);

test('a port above the valid range is rejected', function () {
    (new UfwFirewallManager)->allow(70000);
})->throws(InvalidArgumentException::class);

test('an unsupported protocol is rejected', function () {
    (new UfwFirewallManager)->allow(443, 'icmp');
})->throws(InvalidArgumentException::class);

test('a malformed source address is rejected', function () {
    (new UfwFirewallManager)->allowFrom('not-an-ip', 443);
})->throws(InvalidArgumentException::class);

test('an address carrying shell metacharacters is rejected', function () {
    (new FirewalldFirewallManager)->allowFrom('1.2.3.4; rm -rf /', 443);
})->throws(InvalidArgumentException::class);

test('a cidr range is accepted', function () {
    expect((new UfwFirewallManager)->allowFrom('10.0.0.0/8', 6379))->toContain('10.0.0.0/8');
});

test('a source address ending in a newline is rejected, so it cannot cut the firewall rule short', function (string $address) {
    (new UfwFirewallManager)->allowFrom($address, 6379);
})->with([
    'a cidr range' => ["10.0.0.0/8\n"],
    'a single address' => ["10.0.0.1\n"],
])->throws(InvalidArgumentException::class);

test('the firewall always opens the servers real ssh port, never assuming 22', function () {
    expect((new FirewallConfigurator)->requiredPorts(2222))->toBe([2222, 80, 443]);
});

test('a server already on port 22 does not get a duplicate rule', function () {
    expect((new FirewallConfigurator)->requiredPorts(22))->toBe([22, 80, 443]);
});

test('the sshd config disables passwords and pins modern crypto', function () {
    $config = (new SecurityHardening)->sshdConfig(2222);

    expect($config)->toContain('Port 2222')
        ->and($config)->toContain('PasswordAuthentication no')
        ->and($config)->toContain('PermitRootLogin prohibit-password')
        ->and($config)->toContain('AllowAgentForwarding no')
        ->and($config)->toContain('AllowTcpForwarding no')
        ->and($config)->toContain('X11Forwarding no')
        ->and($config)->toContain('chacha20-poly1305@openssh.com')
        ->and($config)->toContain('curve25519-sha256')
        ->and($config)->toContain('ssh-ed25519');
});

test('the sshd config never pins the wrong port', function () {
    expect((new SecurityHardening)->sshdConfig(2222))->not->toContain('Port 22' . PHP_EOL);
});

test('the fail2ban jail bans after five attempts on the real ssh port', function () {
    $jail = (new SecurityHardening)->fail2banJail(2222);

    expect($jail)->toContain('port = 2222')
        ->and($jail)->toContain('maxretry = 5')
        ->and($jail)->toContain('bantime = 3600')
        ->and($jail)->toContain('findtime = 600');
});

test('the fail2ban jail reads SSH sign-ins from the journal, so it starts on systems with no auth log', function () {
    $jail = (new SecurityHardening)->fail2banJail(22);

    expect($jail)->toContain('backend = systemd')
        ->and($jail)->not->toContain('logpath');
});

test('Debian and Ubuntu get the journal reader the jail needs, the Red Hat family already has it', function (string $os, bool $reader) {
    $command = (new SecurityHardening)->fail2banInstallCommand(Server::factory()->make(['os_name' => $os]));

    expect($command)->toContain('fail2ban')
        ->and(str_contains($command, 'python3-systemd'))->toBe($reader);
})->with([
    ['ubuntu', true],
    ['debian', true],
    ['rocky', false],
    ['fedora', false],
]);

test('Debian and Ubuntu turn on the daily security update run in apt itself', function (string $os) {
    $command = (new SecurityHardening)->automaticUpdatesCommand(Server::factory()->make(['os_name' => $os]));

    expect($command)->toContain('unattended-upgrades')
        ->and($command)->toContain('APT::Periodic::Unattended-Upgrade "1";')
        ->and($command)->toContain('/etc/apt/apt.conf.d/20auto-upgrades')
        ->and($command)->toContain('apt-daily-upgrade.timer');
})->with(['ubuntu', 'debian']);

test('the Red Hat family installs security updates, not only downloads them', function (string $os) {
    $command = (new SecurityHardening)->automaticUpdatesCommand(Server::factory()->make(['os_name' => $os]));

    expect($command)->toContain('dnf-automatic')
        ->and($command)->toContain('/etc/dnf/automatic.conf')
        ->and($command)->toContain('apply_updates = yes')
        ->and($command)->toContain('upgrade_type = security')
        ->and($command)->toContain('systemctl enable --now dnf-automatic.timer');
})->with(['rocky', 'almalinux', 'fedora', 'amzn']);

test("the weekly clean-up removes old packages with each family's own tool, then trims system logs to the cap", function (string $os, string $remove, string $clean) {
    $script = (new SecurityHardening)->maintenanceScript(Server::factory()->make(['os_name' => $os, 'meta' => ['disk_gb' => 80]]));

    expect($script)->toContain($remove)
        ->toContain($clean)
        ->toContain('journalctl --vacuum-size=500M')
        ->toContain('echo "coresmith-freed $((after - before))"');
})->with([
    ['ubuntu', 'apt-get -o DPkg::Lock::Timeout=600 autoremove --purge -y', 'apt-get -o DPkg::Lock::Timeout=600 clean'],
    ['debian', 'apt-get -o DPkg::Lock::Timeout=600 autoremove --purge -y', 'apt-get -o DPkg::Lock::Timeout=600 clean'],
    ['rocky', 'dnf -y autoremove', 'dnf clean packages'],
]);

test('system logs are capped at 500 MB, or 200 MB on a disk under 20 GB, and kept a month at most', function () {
    $hardening = new SecurityHardening;

    expect($hardening->journalCap(Server::factory()->make(['meta' => ['disk_gb' => 80]])))->toContain("[Journal]\nSystemMaxUse=500M\nMaxRetentionSec=1month")
        ->and($hardening->journalCap(Server::factory()->make(['meta' => ['disk_gb' => 15]])))->toContain('SystemMaxUse=200M')
        ->and($hardening->journalCap(Server::factory()->make(['meta' => []])))->toContain('SystemMaxUse=500M');
});

test('the clean-up runs every Sunday at 04:30, spread over an hour, and catches up after the server was off', function () {
    $hardening = new SecurityHardening;

    expect($hardening->maintenanceTimer())->toContain('OnCalendar=Sun *-*-* 04:30:00')
        ->toContain('RandomizedDelaySec=1h')
        ->toContain('Persistent=true')
        ->toContain('WantedBy=timers.target')
        ->and($hardening->maintenanceService())->toContain('Type=oneshot')
        ->toContain('ExecStart=' . SecurityHardening::MAINTENANCE_SCRIPT);
});

test('every hardening sysctl from the spec is present', function (string $sysctl) {
    expect((new SecurityHardening)->sysctlConfig())->toContain($sysctl);
})->with([
    'net.ipv4.conf.all.rp_filter = 1',
    'net.ipv4.conf.all.accept_redirects = 0',
    'net.ipv4.conf.all.accept_source_route = 0',
    'net.ipv4.tcp_syncookies = 1',
    'kernel.kptr_restrict = 2',
    'kernel.yama.ptrace_scope = 1',
    'kernel.dmesg_restrict = 1',
    'fs.protected_hardlinks = 1',
    'fs.protected_symlinks = 1',
]);

test('apparmor complain profiles are counted from json output', function () {
    $json = json_encode(['profiles' => ['a' => 'enforce', 'b' => 'complain', 'c' => 'complain']]);

    expect((new SecurityHardening)->countComplainProfiles((string) $json))->toBe(2);
});

test('apparmor complain profiles are counted from text output', function () {
    expect((new SecurityHardening)->countComplainProfiles('3 profiles are in complain mode.'))->toBe(3);
});

test('a fully enforcing apparmor reports nothing', function () {
    expect((new SecurityHardening)->countComplainProfiles('0 profiles are in complain mode.'))->toBe(0);
});
