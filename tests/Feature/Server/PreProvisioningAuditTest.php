<?php

declare(strict_types=1);

use App\Models\Server;
use App\Services\Server\Audit\PreProvisioningAudit;
use App\Services\SSH\SSHConnection;
use Tests\Support\FakeSSHTransport;

function auditProbe(array $sections = []): string
{
    $sections = array_merge([
        'ports' => ['LISTEN 0 4096 0.0.0.0:22 0.0.0.0:* users:(("sshd",pid=701,fd=3))'],
        'services' => ['cron.service', 'ssh.service', 'systemd-journald.service'],
        'users' => [],
        'disk' => ['41943040'],
        'memory' => ['4028000'],
        'selinux' => [],
        'apparmor' => ['Y'],
        'runtimes' => [],
        'managers' => [],
        'docker' => [],
    ], $sections);

    $out = '';

    foreach ($sections as $name => $lines) {
        $out .= "== {$name}\n" . implode("\n", $lines) . ($lines === [] ? '' : "\n");
    }

    return $out;
}

function auditFindings(string $probe, array $server = []): array
{
    return app(PreProvisioningAudit::class)->report($probe, Server::factory()->make($server))->toArray();
}

function auditFinding(array $findings, string $key): ?array
{
    return collect($findings)->firstWhere('key', $key);
}

test('a clean server has nothing blocking it and says ports 80 and 443 are free', function () {
    $findings = auditFindings(auditProbe());

    expect(collect($findings)->where('severity', 'blocker'))->toBeEmpty()
        ->and(auditFinding($findings, 'ports_free')['severity'])->toBe('info')
        ->and(auditFinding($findings, 'apparmor')['title'])->toBe('AppArmor is on');
});

test('a port scan that produced nothing says so instead of claiming the ports are free', function () {
    $findings = auditFindings(auditProbe(['ports' => []]));

    expect(auditFinding($findings, 'ports_unknown'))->toMatchArray([
        'severity' => 'warning',
        'title' => 'Coresmith could not read the listening ports',
    ])
        ->and(auditFinding($findings, 'ports_free'))->toBeNull();
});

test('nginx on port 80 blocks provisioning once, with a fix to stop it', function () {
    $findings = auditFindings(auditProbe([
        'ports' => ['LISTEN 0 511 0.0.0.0:80 0.0.0.0:* users:(("nginx",pid=812,fd=6))'],
        'services' => ['nginx.service'],
    ]));

    expect(auditFinding($findings, 'port_80'))->toMatchArray([
        'severity' => 'blocker',
        'title' => 'Port 80 is in use by nginx',
        'fix' => 'stop-nginx',
    ])
        ->and(auditFinding($findings, 'service_nginx'))->toBeNull()
        ->and(auditFinding($findings, 'ports_free'))->toBeNull();
});

test('a web server running on other ports is still a blocker', function () {
    $findings = auditFindings(auditProbe([
        'ports' => ['LISTEN 0 511 0.0.0.0:8081 0.0.0.0:* users:(("httpd",pid=812,fd=6))'],
        'services' => ['httpd.service'],
    ]));

    expect(auditFinding($findings, 'service_httpd'))->toMatchArray(['severity' => 'blocker', 'fix' => 'stop-httpd']);
});

test('something else on port 443 blocks without an automatic fix', function () {
    $findings = auditFindings(auditProbe(['ports' => ['LISTEN 0 511 0.0.0.0:443 0.0.0.0:* users:(("traefik",pid=9,fd=6))']]));

    expect(auditFinding($findings, 'port_443'))->toMatchArray(['severity' => 'blocker', 'fix' => null])
        ->and(auditFinding($findings, 'port_443')['detail'])->toContain('Stop traefik');
});

test('a port inside the app range is a warning', function () {
    $findings = auditFindings(auditProbe(['ports' => ['LISTEN 0 511 127.0.0.1:8080 0.0.0.0:* users:(("node",pid=1200,fd=21))']]));

    expect(auditFinding($findings, 'app_port_8080'))->toMatchArray(['severity' => 'warning', 'title' => 'Port 8080 is in use by node']);
});

test('existing databases, redis, php-fpm and docker are reported with what Coresmith does about them', function () {
    $findings = auditFindings(auditProbe([
        'services' => ['mariadb.service', 'postgresql@16-main.service', 'redis-server.service', 'php8.3-fpm.service', 'docker.service'],
        'docker' => ['384000'],
    ]));

    expect(auditFinding($findings, 'mysql')['severity'])->toBe('info')
        ->and(auditFinding($findings, 'mysql')['title'])->toBe('MariaDB is already running')
        ->and(auditFinding($findings, 'postgresql')['severity'])->toBe('info')
        ->and(auditFinding($findings, 'redis')['severity'])->toBe('info')
        ->and(auditFinding($findings, 'php_fpm')['severity'])->toBe('warning')
        ->and(auditFinding($findings, 'docker')['title'])->toBe('Docker is running, using about 375 MB of memory');
});

test('a coresmith user blocks a fresh server but not one Coresmith already started on', function () {
    $probe = auditProbe(['users' => ['coresmith', 'coresmith-app-atlas']]);

    expect(auditFinding(auditFindings($probe), 'user_coresmith')['severity'])->toBe('blocker')
        ->and(auditFinding(auditFindings($probe, ['provisioning_phase' => 3]), 'user_coresmith')['severity'])->toBe('info')
        ->and(auditFinding(auditFindings($probe), 'app_users')['title'])->toBe('1 Coresmith app user exists');
});

test("the panel's own host is refused with one blocker and none of the advice that would break the panel", function (array $panel, array $server) {
    $findings = auditFindings(auditProbe([
        'ports' => ['LISTEN 0 4096 *:80 *:* users:(("caddy",pid=900,fd=7))', 'LISTEN 0 4096 *:443 *:* users:(("caddy",pid=900,fd=8))', 'LISTEN 0 4096 127.0.0.1:6001 0.0.0.0:* users:(("php",pid=950,fd=9))'],
        'services' => ['caddy.service', 'coresmith-horizon.service', 'php8.5-fpm.service', 'redis-server.service'],
        'users' => ['coresmith'],
        'panel' => $panel,
    ]), $server);

    expect($findings)->toBe([[
        'key' => 'panel_host',
        'severity' => 'blocker',
        'title' => 'This server runs Coresmith itself',
        'detail' => 'Add a different server for your apps.',
        'fix' => null,
    ]]);
})->with([
    'its directory' => [['/opt/coresmith'], []],
    'only its units' => [['/etc/systemd/system/coresmith-horizon.service', '/etc/systemd/system/coresmith-scheduler.timer'], []],
    'once provisioning has started' => [['/opt/coresmith', '/etc/systemd/system/coresmith-reverb.service'], ['provisioning_phase' => 3]],
]);

test('the probe looks for the panel on the host without changing anything', function () {
    $transport = new FakeSSHTransport;
    $transport->respondTo('base64 -d', auditProbe());
    $server = Server::factory()->create(['host_key_fingerprint' => null, 'ssh_user' => 'root']);

    app(PreProvisioningAudit::class)->run((new SSHConnection($server, $transport))->connect());

    expect(base64_decode(explode("'", $transport->commands()[0])[1]))
        ->toContain("echo '== panel'\nls -d /opt/coresmith /etc/systemd/system/coresmith-horizon.service /etc/systemd/system/coresmith-reverb.service /etc/systemd/system/coresmith-scheduler.timer /etc/systemd/system/coresmith-update.path 2>/dev/null\n");
});

test('disk and memory thresholds', function (string $disk, string $memory, ?string $diskSeverity, ?string $memorySeverity) {
    $findings = auditFindings(auditProbe(['disk' => [$disk], 'memory' => [$memory]]));

    expect(auditFinding($findings, 'disk')['severity'] ?? null)->toBe($diskSeverity)
        ->and(auditFinding($findings, 'memory')['severity'] ?? null)->toBe($memorySeverity);
})->with([
    'plenty' => ['41943040', '4028000', null, null],
    'tight' => ['7340032', '786432', 'warning', 'warning'],
    'too small' => ['3145728', '262144', 'blocker', 'blocker'],
]);

test('selinux enforcing is a warning that says Coresmith has no policies for it', function () {
    $finding = auditFinding(auditFindings(auditProbe(['selinux' => ['Enforcing'], 'apparmor' => []])), 'selinux');

    expect($finding['severity'])->toBe('warning')
        ->and($finding['detail'])->toContain('no SELinux policies');
});

test('system runtimes and version managers are noted', function () {
    $findings = auditFindings(auditProbe([
        'runtimes' => ['php PHP 8.1.2-1ubuntu2.14 (cli) (built: Aug 18 2023 11:41:11) (NTS)', 'node v18.19.1'],
        'managers' => ['/home/maya/.nvm'],
    ]));

    expect(auditFinding($findings, 'runtime_php')['title'])->toBe('PHP 8.1.2 is installed from system packages')
        ->and(auditFinding($findings, 'runtime_node')['title'])->toBe('Node.js 18.19.1 is installed from system packages')
        ->and(auditFinding($findings, 'manager_nvm')['title'])->toBe('nvm is installed in /home/maya');
});

test('findings come back with blockers first, then warnings, then info', function () {
    $severities = array_column(auditFindings(auditProbe([
        'ports' => ['LISTEN 0 511 0.0.0.0:80 0.0.0.0:* users:(("nginx",pid=812,fd=6))', 'LISTEN 0 511 127.0.0.1:8080 0.0.0.0:* users:(("node",pid=1,fd=2))'],
        'services' => ['nginx.service'],
    ])), 'severity');

    expect($severities)->toBe(collect($severities)->sortBy(fn(string $severity): int => ['blocker' => 0, 'warning' => 1, 'info' => 2][$severity])->values()->all());
});

test('the probe runs as root in one round trip and never changes anything', function () {
    $transport = new FakeSSHTransport;
    $transport->respondTo('base64 -d', auditProbe());
    $server = Server::factory()->create(['host_key_fingerprint' => null, 'ssh_user' => 'deploy']);

    app(PreProvisioningAudit::class)->run((new SSHConnection($server, $transport))->connect());

    expect($transport->commands())->toHaveCount(1)
        ->and($transport->commands()[0])->toEndWith('| base64 -d | sudo bash -s')
        ->and(base64_decode(explode("'", $transport->commands()[0])[1]))->not->toMatch('/\b(apt|yum|dnf|rm|systemctl (stop|disable|restart))\b/');
});
