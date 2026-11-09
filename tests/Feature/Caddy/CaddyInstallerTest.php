<?php

declare(strict_types=1);

use App\Models\Server;
use App\Services\Caddy\CaddyApiClient;
use App\Services\Caddy\CaddyInstaller;
use App\Services\Caddy\CoresmithSite;
use App\Services\Server\MetricsCollector;
use App\Services\SSH\SSHConnection;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Sleep;
use Tests\Support\FakeSSHTransport;

function caddyInstall(FakeSSHTransport $transport, string $user = 'root'): void
{
    $server = Server::factory()->create(['host_key_fingerprint' => null, 'os_name' => 'ubuntu', 'ssh_user' => $user]);

    app(CaddyInstaller::class)->install((new SSHConnection($server, $transport))->connect());
}

test('the config and the unit override are written as root, so a sudo user can install caddy', function () {
    $transport = new FakeSSHTransport;

    caddyInstall($transport, 'deploy');

    $staged = FakeSSHTransport::STAGED_UPLOAD;
    $copy = fn(string $target): string => 'sudo bash -c ' . escapeshellarg("if [ -e '{$target}' ]; then cat '{$staged}' > '{$target}'; else install -m 644 '{$staged}' '{$target}'; fi");

    expect($transport->commands())->toContain($copy(CaddyInstaller::CONFIG_FILE))
        ->toContain($copy(CaddyInstaller::SERVICE_DROP_IN))
        ->toContain('sudo systemctl daemon-reload')
        ->toContain('sudo systemctl restart caddy');
});

test('caddy owns the folder its access log goes in, so a package that leaves it to root does not stop caddy starting', function () {
    $transport = new FakeSSHTransport;

    caddyInstall($transport);

    $commands = $transport->commands();
    $owned = array_key_first(array_filter($commands, fn(string $command): bool => str_contains($command, 'chown caddy:caddy ' . dirname(MetricsCollector::ACCESS_LOG))));
    $restart = array_key_first(array_filter($commands, fn(string $command): bool => str_contains($command, 'systemctl restart caddy')));

    expect($owned)->toBeInt()
        ->and($owned)->toBeLessThan($restart);
});

test('a managed server gets caddy from its own repository, even where an ubuntu pro mirror ranks its older build higher', function () {
    $transport = new FakeSSHTransport;

    caddyInstall($transport);

    $commands = $transport->commands();
    $pin = array_key_first(array_filter($commands, fn(string $command): bool => str_contains($command, 'Package: caddy\\nPin: origin dl.cloudsmith.io\\nPin-Priority: 1001')));
    $install = array_key_first(array_filter($commands, fn(string $command): bool => str_contains($command, "install -y --no-install-recommends 'caddy'")));

    expect($pin)->toBeInt()
        ->and($install)->toBeInt()
        ->and($pin)->toBeLessThan($install);
});

test('caddy starts from the config the panel keeps, so app routes come back after a reboot', function () {
    $transport = new FakeSSHTransport;

    caddyInstall($transport);

    expect($transport->uploads()[CaddyInstaller::SERVICE_DROP_IN])
        ->toContain("ExecStart=\nExecStart=/usr/bin/caddy run --environ --config " . CaddyInstaller::CONFIG_FILE)
        ->toContain("ExecReload=\nExecReload=/usr/bin/caddy reload --config " . CaddyInstaller::CONFIG_FILE . ' --force')
        ->and(strpos(implode("\n", $transport->commands()), 'daemon-reload'))->toBeLessThan(strpos(implode("\n", $transport->commands()), 'systemctl restart caddy'));
});

test('app routes go to a server caddy actually has, listening for https with nothing in front of them', function () {
    $config = json_decode((new CaddyInstaller)->config(), true);
    $server = $config['apps']['http']['servers'][CaddyInstaller::SERVER];

    expect($server['listen'])->toBe([':443'])
        ->and($server['routes'])->toBe([])
        ->and(CaddyApiClient::ROUTES_PATH)->toBe('/config/apps/http/servers/' . CaddyInstaller::SERVER . '/routes');
});

test('access logs are written as json where the panel reads them', function () {
    $raw = (new CaddyInstaller)->config();
    $config = json_decode($raw, true);

    expect($config['logging']['logs']['access'])->toBe([
        'writer' => ['output' => 'file', 'filename' => MetricsCollector::ACCESS_LOG],
        'encoder' => ['format' => 'json'],
        'include' => ['http.log.access'],
    ])->and($raw)->toContain('"logs": {}');
});

test('a reprovisioned server keeps the app routes caddy already had', function () {
    $route = ['@id' => 'app-01', 'match' => [['host' => ['shop.example.com']]], 'terminal' => true];
    $current = json_encode(['apps' => ['http' => ['servers' => [CaddyInstaller::SERVER => ['routes' => [$route]]]]]]);
    $transport = (new FakeSSHTransport)->respondTo('cat ' . CaddyInstaller::CONFIG_FILE, (string) $current);

    caddyInstall($transport);

    $written = json_decode($transport->uploads()[CaddyInstaller::CONFIG_FILE], true);

    expect($written['apps']['http']['servers'][CaddyInstaller::SERVER]['routes'])->toBe([$route]);
});

test('a config that is not ours, or not json, starts with no routes', function (string $current) {
    $config = json_decode((new CaddyInstaller)->config(null, $current), true);

    expect($config['apps']['http']['servers'][CaddyInstaller::SERVER]['routes'])->toBe([]);
})->with(['{}', 'not json', '[1,2]', '{"apps":[]}', '{"apps":{"http":{"servers":{"coresmith":{"routes":{"a":1}}}}}}']);

test('kept routes stay exactly as caddy wrote them, empty objects included', function () {
    $current = '{"apps":{"http":{"servers":{"coresmith":{"routes":[{"handle":[{"handler":"encode","encodings":{"gzip":{},"zstd":{}}}]}]}}}}}';

    expect(preg_replace('/\s+/', '', (new CaddyInstaller)->config(null, $current)))->toContain('"encodings":{"gzip":{},"zstd":{}}');
});

test('the acme email is only written when a valid one is known', function (?string $email, bool $written) {
    $config = json_decode((new CaddyInstaller)->config($email), true);

    expect(isset($config['apps']['tls']))->toBe($written);

    if ($written) {
        expect($config['apps']['tls']['automation']['policies'][0]['issuers'])->toBe([
            ['module' => 'acme', 'email' => $email],
            ['module' => 'acme', 'ca' => 'https://acme.zerossl.com/v2/DV90', 'email' => $email],
        ]);
    }
})->with([
    'no email' => [null, false],
    'a valid email' => ['ops@example.com', true],
    'an email that would break the config' => ['ops@example.com} {', false],
]);

test('a new server keeps the admin api on a socket in a folder only caddy can enter, and is recorded as moved', function () {
    $transport = new FakeSSHTransport;
    $server = Server::factory()->create(['host_key_fingerprint' => null, 'os_name' => 'ubuntu']);

    app(CaddyInstaller::class)->install((new SSHConnection($server, $transport))->connect());

    $commands = $transport->commands();
    $tmpfiles = array_key_first(array_filter($commands, fn(string $command): bool => str_contains($command, 'systemd-tmpfiles --create /etc/tmpfiles.d/coresmith-caddy.conf')));
    $restart = array_key_first(array_filter($commands, fn(string $command): bool => str_contains($command, 'systemctl restart caddy')));

    expect($transport->uploads()['/etc/tmpfiles.d/coresmith-caddy.conf'] ?? null)->toBe("d /run/caddy-admin 0700 caddy caddy -\n")
        ->and($tmpfiles)->toBeInt()->toBeLessThan($restart)
        ->and(end($commands))->toBe("curl -fsS --max-time 10 --unix-socket /run/caddy-admin/admin.sock 'http://localhost/config/' >/dev/null")
        ->and($server->fresh()->caddyAdminOnSocket())->toBeTrue();
});

test('a sudo user probes the admin socket through sudo, since only caddy and root can open it', function () {
    $transport = new FakeSSHTransport;

    caddyInstall($transport, 'deploy');

    expect($transport->commands())->toContain('sudo bash -c ' . escapeshellarg("curl -fsS --max-time 10 --unix-socket /run/caddy-admin/admin.sock 'http://localhost/config/' >/dev/null"));
});

function caddyThisMachineInstall(FakeSSHTransport $transport): Server
{
    $server = Server::factory()->create(['host_key_fingerprint' => null, 'os_name' => 'ubuntu', 'runs_coresmith' => true, 'ip_address' => '127.0.0.1']);

    app(CaddyInstaller::class)->install((new SSHConnection($server, $transport))->connect());

    return $server;
}

function caddyThisMachineAdapted(): string
{
    return (string) file_get_contents(base_path('tests/Fixtures/Caddy/adapted-public.json'));
}

test("on Coresmith's own machine, provisioning folds Coresmith's site into the config instead of replacing it", function () {
    $transport = (new FakeSSHTransport)->respondTo('caddy adapt', caddyThisMachineAdapted());

    caddyThisMachineInstall($transport);

    $written = (string) $transport->uploads()[CaddyInstaller::CONFIG_FILE];
    $config = json_decode($written, true);

    expect($transport->commands())->toContain('caddy adapt --config /etc/caddy/Caddyfile --adapter caddyfile 2>/dev/null')
        ->and(array_column($config['apps']['http']['servers'][CaddyInstaller::SERVER]['routes'], '@id'))->toBe(['coresmith-site', 'coresmith-any'])
        ->and(array_keys($config['apps']['http']['servers']))->toBe([CaddyInstaller::SERVER, 'coresmith-internal'])
        ->and($config['admin'])->toBe(['listen' => 'unix//run/caddy-admin/admin.sock|0222'])
        ->and($written)->toContain('"gzip": {}');
});

test("on Coresmith's own machine the admin socket stays open to Coresmith's group, which its address changes use", function () {
    $transport = (new FakeSSHTransport)->respondTo('caddy adapt', caddyThisMachineAdapted());

    $server = caddyThisMachineInstall($transport);

    expect($transport->uploads()['/etc/tmpfiles.d/coresmith-caddy.conf'] ?? null)->toBe("d /run/caddy-admin 0750 caddy coresmith -\n")
        ->and($server->fresh()->caddyAdminOnSocket())->toBeTrue();
});

test("on Coresmith's own machine, reprovisioning keeps the apps between Coresmith's site and its catch-all", function () {
    $current = (new CaddyInstaller)->thisMachineConfig(CoresmithSite::fromAdapted(caddyThisMachineAdapted()), null);
    $decoded = json_decode($current, false);
    array_splice($decoded->apps->http->servers->coresmith->routes, 1, 0, [json_decode('{"@id":"app-01","match":[{"host":["shop.example.com"]}],"terminal":true}')]);
    $transport = (new FakeSSHTransport)
        ->respondTo('caddy adapt', caddyThisMachineAdapted())
        ->respondTo('cat ' . CaddyInstaller::CONFIG_FILE, (string) json_encode($decoded));

    caddyThisMachineInstall($transport);

    $config = json_decode($transport->uploads()[CaddyInstaller::CONFIG_FILE], true);

    expect(array_column($config['apps']['http']['servers'][CaddyInstaller::SERVER]['routes'], '@id'))->toBe(['coresmith-site', 'app-01', 'coresmith-any'])
        ->and(array_column($config['apps']['tls']['automation']['policies'], '@id'))->toBe(['app-01-tls', 'coresmith-site-tls', 'coresmith-any-tls']);
});

test("if Caddy can't read Coresmith's own site, provisioning stops before it changes Caddy", function () {
    $transport = (new FakeSSHTransport)->respondTo('caddy adapt', 'Error: adapting config using caddyfile: open /etc/caddy/Caddyfile: no such file');

    expect(fn() => caddyThisMachineInstall($transport))->toThrow(RuntimeException::class, CoresmithSite::UNREADABLE)
        ->and($transport->uploads())->not->toHaveKey(CaddyInstaller::CONFIG_FILE)
        ->and($transport->commands())->not->toContain('systemctl restart caddy');
});

test("provisioning Coresmith's own machine waits for an address change to finish with Caddy, so neither undoes the other", function () {
    Sleep::fake(syncWithCarbon: true);
    Cache::lock(CaddyApiClient::THIS_MACHINE_LOCK, 600)->get();
    $transport = (new FakeSSHTransport)->respondTo('caddy adapt', caddyThisMachineAdapted());

    expect(fn() => caddyThisMachineInstall($transport))->toThrow(LockTimeoutException::class)
        ->and($transport->uploads())->not->toHaveKey(CaddyInstaller::CONFIG_FILE)
        ->and($transport->commands())->not->toContain('systemctl restart caddy');
});
