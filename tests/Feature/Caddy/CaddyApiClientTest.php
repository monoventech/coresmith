<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Exceptions\InvalidRouteConfigException;
use App\Exceptions\SSHCommandFailedException;
use App\Jobs\SyncApplicationRoute;
use App\Models\Application;
use App\Models\InstanceSetting;
use App\Models\Server;
use App\Services\Caddy\CaddyApiClient;
use App\Services\Caddy\CaddyInstaller;
use App\Services\Caddy\CaddyRouteBuilder;
use App\Services\Caddy\RouteConfigValidator;
use App\Services\SSH\SSHConnection;
use App\Services\SSH\SSHConnectionManager;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Sleep;
use Tests\Support\FakeSSHTransport;

function caddyConnection(FakeSSHTransport $transport): SSHConnection
{
    $server = Server::factory()->create(['host_key_fingerprint' => null, 'os_name' => 'ubuntu']);

    return (new SSHConnection($server, $transport))->connect();
}

function caddySocketConnection(FakeSSHTransport $transport): SSHConnection
{
    $server = Server::factory()->create(['host_key_fingerprint' => null, 'os_name' => 'ubuntu', 'meta' => ['caddy_admin' => 'socket']]);

    return (new SSHConnection($server, $transport))->connect();
}

function caddyClient(): CaddyApiClient
{
    return new CaddyApiClient(new CaddyRouteBuilder, new RouteConfigValidator);
}

function routableApp(array $attributes = []): Application
{
    return Application::factory()->create(array_merge([
        'slug' => 'my-app',
        'domain' => 'app.example.com',
        'type' => ApplicationType::Php,
        'base_port' => 13000,
    ], $attributes));
}

test('adding a site posts the route to the admin api on localhost', function () {
    $transport = new FakeSSHTransport;

    caddyClient()->addSite(caddyConnection($transport), routableApp());

    $post = $transport->commands()[0];

    expect($post)->toContain('-X POST')
        ->and($post)->toContain('http://localhost:2019/config/apps/http/servers/coresmith/routes')
        ->and($post)->toContain('app.example.com');
});

test('the admin api is only ever addressed on localhost', function () {
    $transport = new FakeSSHTransport;

    caddyClient()->addSite(caddyConnection($transport), routableApp());

    foreach ($transport->commands() as $command) {
        expect($command)->toContain('http://localhost:2019');
    }
});

function caddyCalls(FakeSSHTransport $transport, string $needle): int
{
    return count(array_filter($transport->commands(), fn(string $command): bool => str_contains($command, $needle)));
}

test('putting a site patches the route when caddy already has it', function () {
    $transport = new FakeSSHTransport;
    $app = routableApp();

    caddyClient()->putSite(caddyConnection($transport), $app);

    expect(implode("\n", $transport->commands()))->toContain("-X PATCH 'http://localhost:2019/id/app-{$app->id}'")
        ->not->toContain('-X POST');
});

test('putting a site adds the route when caddy has never seen it', function () {
    $transport = (new FakeSSHTransport)->respondTo("-X GET 'http://localhost:2019/id/", 'curl: (22) The requested URL returned error: 404', 22);

    caddyClient()->putSite(caddyConnection($transport), routableApp());

    expect(implode("\n", $transport->commands()))->toContain("-X POST 'http://localhost:2019/config/apps/http/servers/coresmith/routes'")
        ->not->toContain('-X PATCH');
});

test('a busy admin api is retried until it answers', function () {
    $transport = (new FakeSSHTransport)->respondToSequence('-X PATCH', [
        ['curl: (22) The requested URL returned error: 503', 22],
        ['curl: (7) Failed to connect to localhost port 2019', 7],
        ['', 0],
    ]);

    caddyClient()->putSite(caddyConnection($transport), routableApp());

    expect(caddyCalls($transport, '-X PATCH'))->toBe(3);
});

test('a route caddy rejects is not retried', function () {
    $transport = (new FakeSSHTransport)->respondTo('-X PATCH', 'curl: (22) The requested URL returned error: 400', 22);

    expect(fn() => caddyClient()->putSite(caddyConnection($transport), routableApp()))->toThrow(SSHCommandFailedException::class);
    expect(caddyCalls($transport, '-X PATCH'))->toBe(1);
});

test('the admin api is given up on after three retries', function () {
    $transport = (new FakeSSHTransport)->respondTo('-X PATCH', 'curl: (28) Operation timed out', 28);

    expect(fn() => caddyClient()->putSite(caddyConnection($transport), routableApp()))->toThrow(SSHCommandFailedException::class);
    expect(caddyCalls($transport, '-X PATCH'))->toBe(4);
});

test('updating a site patches by route id', function () {
    $transport = new FakeSSHTransport;
    $app = routableApp();

    caddyClient()->updateSite(caddyConnection($transport), $app);

    expect($transport->commands()[0])->toContain('-X PATCH')
        ->and($transport->commands()[0])->toContain('/id/app-' . $app->id);
});

test('removing a site deletes by route id and sends no payload', function () {
    $transport = new FakeSSHTransport;
    $app = routableApp();

    caddyClient()->removeSite(caddyConnection($transport), $app);

    expect($transport->commands()[0])->toContain('-X DELETE')
        ->and($transport->commands()[0])->toContain('/id/app-' . $app->id)
        ->and($transport->commands()[0])->not->toContain('-d ');
});

test('a mutation is followed by a config persist', function () {
    $transport = new FakeSSHTransport;

    caddyClient()->addSite(caddyConnection($transport), routableApp());

    $commands = $transport->commands();

    expect($commands)->toHaveCount(2)
        ->and($commands[1])->toContain('-X GET')
        ->and($commands[1])->toContain('> ' . CaddyApiClient::STAGED_CONFIG_FILE)
        ->and($commands[1])->toContain('&& mv -f ' . CaddyApiClient::STAGED_CONFIG_FILE . ' ' . CaddyInstaller::CONFIG_FILE)
        ->and($commands[1])->not->toContain("-X GET 'http://localhost:2019/config/' > " . CaddyInstaller::CONFIG_FILE);
});

test('a persist that fails never truncates the config caddy would reload', function () {
    $transport = (new FakeSSHTransport)->respondTo('-X GET', 'curl: (22) The requested URL returned error: 400', 22);

    expect(fn() => caddyClient()->addSite(caddyConnection($transport), routableApp()))->toThrow(SSHCommandFailedException::class);

    expect(implode("\n", $transport->commands()))->not->toContain('> ' . CaddyInstaller::CONFIG_FILE);
});

test('only idempotent verbs are retried', function () {
    $transport = (new FakeSSHTransport)->respondTo('-X POST', 'curl: (28) Operation timed out', 28);

    expect(fn() => caddyClient()->addSite(caddyConnection($transport), routableApp()))->toThrow(SSHCommandFailedException::class);
    expect(caddyCalls($transport, '-X POST'))->toBe(1);
});

test('syncing a route puts the site and persists, and a domainless app touches nothing', function () {
    $transport = new FakeSSHTransport;
    $app = routableApp();

    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $transport);

    try {
        app()->call([new SyncApplicationRoute($app), 'handle']);

        $commands = implode("\n", $transport->commands());

        expect($commands)->toContain('/id/app-' . $app->id)
            ->and($commands)->toContain('mv -f ' . CaddyApiClient::STAGED_CONFIG_FILE)
            ->and($transport->connected())->toBeFalse();

        $quiet = new FakeSSHTransport;
        SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $quiet);

        app()->call([new SyncApplicationRoute(routableApp(['slug' => 'no-domain', 'domain' => null])), 'handle']);

        expect($quiet->commands())->toBe([]);
    } finally {
        SSHConnectionManager::createTransportUsing(null);
    }
});

test('a route for an app whose domains were all removed is taken down', function () {
    $transport = new FakeSSHTransport;
    $app = routableApp(['slug' => 'unrouted', 'domain' => null]);
    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $transport);

    try {
        app()->call([new SyncApplicationRoute($app, unroute: true), 'handle']);
    } finally {
        SSHConnectionManager::createTransportUsing(null);
    }

    expect(implode("\n", $transport->commands()))->toContain("-X DELETE 'http://localhost:2019/id/app-{$app->id}'")
        ->toContain('mv -f ' . CaddyApiClient::STAGED_CONFIG_FILE);
});

test('taking down a route caddy never had is not an error', function () {
    $transport = (new FakeSSHTransport)->respondTo("-X DELETE 'http://localhost:2019/id/", 'curl: (22) The requested URL returned error: 404', 22);
    $app = routableApp(['slug' => 'never-routed', 'domain' => null]);
    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $transport);

    try {
        app()->call([new SyncApplicationRoute($app, unroute: true), 'handle']);
    } finally {
        SSHConnectionManager::createTransportUsing(null);
    }

    expect(caddyCalls($transport, '-X DELETE'))->toBe(1)
        ->and(implode("\n", $transport->commands()))->not->toContain('mv -f ' . CaddyApiClient::STAGED_CONFIG_FILE);
});

test('an invalid route is never pushed to caddy', function () {
    $transport = new FakeSSHTransport;
    $app = routableApp(['domain' => null]);

    try {
        caddyClient()->addSite(caddyConnection($transport), $app);
        $this->fail('Expected InvalidRouteConfigException');
    } catch (InvalidRouteConfigException) {
        expect($transport->commands())->toBe([]);
    }
});

test('the bootstrap config puts the admin api on a socket only caddy and root can open', function () {
    $config = json_decode((new CaddyInstaller)->config(), true);

    expect($config['admin'])->toBe(['listen' => 'unix//run/caddy-admin/admin.sock']);
});

test('the bootstrap config never contains a managed application site', function () {
    expect((new CaddyInstaller)->config())->not->toContain('/var/coresmith/apps');
});

test('every admin api call to a server still on tcp carries the origin caddy enforces, or caddy answers 403', function () {
    $transport = new FakeSSHTransport;

    caddyClient()->addSite(caddyConnection($transport), routableApp());

    expect($transport->commands())->not->toBeEmpty();

    foreach ($transport->commands() as $command) {
        expect($command)->toContain('Origin: http://localhost:2019');
    }

    expect(CaddyApiClient::probe(Server::factory()->make()))->toContain("-H 'Origin: http://localhost:2019'");
});

test('a server whose admin api moved to the socket is only ever reached through it, with no origin to forge', function () {
    $transport = new FakeSSHTransport;

    caddyClient()->addSite(caddySocketConnection($transport), routableApp());

    expect($transport->commands())->not->toBeEmpty()
        ->and($transport->commands()[0])->toContain("-X POST --unix-socket /run/caddy-admin/admin.sock 'http://localhost/config/apps/http/servers/coresmith/routes'");

    foreach ($transport->commands() as $command) {
        expect($command)->toContain('--unix-socket /run/caddy-admin/admin.sock')
            ->not->toContain('localhost:2019')
            ->not->toContain('Origin:');
    }
});

test('the probe asks the admin api where the server keeps it', function () {
    expect(CaddyApiClient::probe(Server::factory()->make(['meta' => ['caddy_admin' => 'socket']])))
        ->toBe("curl -fsS --max-time 10 --unix-socket /run/caddy-admin/admin.sock 'http://localhost/config/' >/dev/null")
        ->and(CaddyApiClient::probe(Server::factory()->make()))
        ->toBe("curl -fsS --max-time 10 'http://localhost:2019/config/' -H 'Origin: http://localhost:2019' >/dev/null");
});

function caddyThisMachineConnection(FakeSSHTransport $transport): SSHConnection
{
    $server = Server::factory()->create(['host_key_fingerprint' => null, 'os_name' => 'ubuntu', 'runs_coresmith' => true, 'ip_address' => '127.0.0.1', 'meta' => ['caddy_admin' => 'socket']]);

    return (new SSHConnection($server, $transport))->connect();
}

function caddyThisMachineCommand(FakeSSHTransport $transport, string $method, string $path): ?string
{
    return collect($transport->commands())->first(fn(string $command): bool => str_contains($command, "-X {$method} --unix-socket /run/caddy-admin/admin.sock 'http://localhost{$path}'"));
}

test("on Coresmith's machine a new app goes in right after Coresmith's site, never after its catch-all", function () {
    $transport = new FakeSSHTransport;
    $application = routableApp();

    caddyClient()->addSite(caddyThisMachineConnection($transport), $application);

    $route = caddyThisMachineCommand($transport, 'PUT', '/config/apps/http/servers/coresmith/routes/1');

    expect($route)->toContain('"@id":"app-' . $application->getKey() . '"')
        ->and(caddyThisMachineCommand($transport, 'POST', '/config/apps/http/servers/coresmith/routes'))->toBeNull()
        ->and(collect($transport->commands())->last())->toBe("sh -c '/usr/local/libexec/coresmith/persist-caddy-config'");
});

test("on Coresmith's machine each app gets a certificate policy of its own, ahead of Coresmith's on-demand catch-all", function () {
    InstanceSetting::put('notification_email', 'ops@example.com');
    $transport = new FakeSSHTransport;
    $application = routableApp();

    caddyClient()->addSite(caddyThisMachineConnection($transport), $application);

    $policy = caddyThisMachineCommand($transport, 'PUT', '/config/apps/tls/automation/policies/0');

    expect($policy)->toContain(escapeshellarg((string) json_encode([
        '@id' => 'app-' . $application->getKey() . '-tls',
        'subjects' => ['app.example.com'],
        'issuers' => [
            ['module' => 'acme', 'email' => 'ops@example.com'],
            ['module' => 'acme', 'ca' => 'https://acme.zerossl.com/v2/DV90', 'email' => 'ops@example.com'],
        ],
    ], JSON_UNESCAPED_SLASHES)));
});

test("inserting into Caddy's lists is never retried, so a slow answer can't leave a second copy", function () {
    $transport = (new FakeSSHTransport)->respondTo('-X PUT', 'curl: (28) Operation timed out', 28);

    expect(fn() => caddyClient()->addSite(caddyThisMachineConnection($transport), routableApp()))->toThrow(SSHCommandFailedException::class)
        ->and(collect($transport->commands())->filter(fn(string $command) => str_contains($command, '-X PUT'))->count())->toBe(1);
});

test("updating an app on Coresmith's machine updates its route and its certificate policy", function () {
    $transport = new FakeSSHTransport;
    $application = routableApp();

    caddyClient()->updateSite(caddyThisMachineConnection($transport), $application);

    expect(caddyThisMachineCommand($transport, 'PATCH', '/id/app-' . $application->getKey()))->not->toBeNull()
        ->and(caddyThisMachineCommand($transport, 'PATCH', '/id/app-' . $application->getKey() . '-tls'))->toContain('"subjects":["app.example.com"]');
});

test("an app whose certificate policy went missing gets it back when it's updated", function () {
    $application = routableApp();
    $transport = (new FakeSSHTransport)->respondTo("-X PATCH --unix-socket /run/caddy-admin/admin.sock 'http://localhost/id/app-{$application->getKey()}-tls'", 'curl: (22) The requested URL returned error: 404', 22);

    caddyClient()->updateSite(caddyThisMachineConnection($transport), $application);

    expect(caddyThisMachineCommand($transport, 'PUT', '/config/apps/tls/automation/policies/0'))->toContain('"@id":"app-' . $application->getKey() . '-tls"');
});

test("putting an app on Coresmith's machine updates both when Caddy has it, and inserts both when it doesn't", function (bool $known) {
    $application = routableApp();
    $transport = new FakeSSHTransport;

    if (!$known) {
        $transport->respondTo("-X GET --unix-socket /run/caddy-admin/admin.sock 'http://localhost/id/app-{$application->getKey()}'", 'curl: (22) The requested URL returned error: 404', 22);
    }

    caddyClient()->putSite(caddyThisMachineConnection($transport), $application);

    expect(caddyThisMachineCommand($transport, 'PATCH', '/id/app-' . $application->getKey()) !== null)->toBe($known)
        ->and(caddyThisMachineCommand($transport, 'PUT', '/config/apps/http/servers/coresmith/routes/1') !== null)->toBe(!$known)
        ->and(caddyThisMachineCommand($transport, 'PUT', '/config/apps/tls/automation/policies/0') !== null)->toBe(!$known);
})->with(['already there' => [true], 'never seen' => [false]]);

test("removing an app on Coresmith's machine takes out its route and its policy, and a policy already gone isn't an error", function () {
    $application = routableApp();
    $transport = (new FakeSSHTransport)->respondTo("-X DELETE --unix-socket /run/caddy-admin/admin.sock 'http://localhost/id/app-{$application->getKey()}-tls'", 'curl: (22) The requested URL returned error: 404', 22);

    caddyClient()->removeSite(caddyThisMachineConnection($transport), $application);

    expect(caddyThisMachineCommand($transport, 'DELETE', '/id/app-' . $application->getKey()))->not->toBeNull()
        ->and(caddyThisMachineCommand($transport, 'DELETE', '/id/app-' . $application->getKey() . '-tls'))->not->toBeNull()
        ->and(collect($transport->commands())->last())->toBe("sh -c '/usr/local/libexec/coresmith/persist-caddy-config'");
});

test("changes to Caddy on Coresmith's machine wait for one another, so an address change and a deploy can't undo each other", function () {
    Sleep::fake(syncWithCarbon: true);
    $transport = new FakeSSHTransport;
    $connection = caddyThisMachineConnection($transport);
    Cache::lock(CaddyApiClient::THIS_MACHINE_LOCK, 600)->get();

    expect(fn() => caddyClient()->addSite($connection, routableApp()))->toThrow(LockTimeoutException::class)
        ->and($transport->commands())->toBe([]);
});
