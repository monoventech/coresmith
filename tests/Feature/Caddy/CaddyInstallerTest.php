<?php

declare(strict_types=1);

use App\Models\Server;
use App\Services\Caddy\CaddyApiClient;
use App\Services\Caddy\CaddyInstaller;
use App\Services\Server\MetricsCollector;
use App\Services\SSH\SSHConnection;
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
