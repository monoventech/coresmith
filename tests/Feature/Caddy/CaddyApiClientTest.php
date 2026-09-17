<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Exceptions\InvalidRouteConfigException;
use App\Exceptions\SSHCommandFailedException;
use App\Jobs\SyncApplicationRoute;
use App\Models\Application;
use App\Models\Server;
use App\Services\Caddy\CaddyApiClient;
use App\Services\Caddy\CaddyInstaller;
use App\Services\Caddy\CaddyRouteBuilder;
use App\Services\Caddy\RouteConfigValidator;
use App\Services\SSH\SSHConnection;
use App\Services\SSH\SSHConnectionManager;
use Tests\Support\FakeSSHTransport;

function caddyConnection(FakeSSHTransport $transport): SSHConnection
{
    $server = Server::factory()->create(['host_key_fingerprint' => null, 'os_name' => 'ubuntu']);

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

test('a persisted config caddy refuses on boot is an error, a missing one is not', function () {
    $refused = (new FakeSSHTransport)->respondTo('-X POST', 'curl: (22) The requested URL returned error: 400', 22);

    expect(fn() => caddyClient()->loadPersistedConfig(caddyConnection($refused)))->toThrow(SSHCommandFailedException::class);

    $missing = new FakeSSHTransport;

    caddyClient()->loadPersistedConfig(caddyConnection($missing));

    expect($missing->commands()[0])->toContain('test -s ' . CaddyInstaller::CONFIG_FILE . ' || exit 0')
        ->and($missing->commands()[0])->not->toContain('|| true');
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

test('the bootstrap caddyfile refuses unknown hosts and keeps admin on localhost', function () {
    $caddyfile = (new CaddyInstaller)->caddyfile();

    expect($caddyfile)->toContain('admin localhost:2019')
        ->and($caddyfile)->toContain('enforce_origin')
        ->and($caddyfile)->toContain('respond 444')
        ->and($caddyfile)->toContain('import /etc/caddy/panel.caddy');
});

test('the caddyfile never contains a managed application site', function () {
    expect((new CaddyInstaller)->caddyfile())->not->toContain('/var/coresmith/apps');
});
