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
