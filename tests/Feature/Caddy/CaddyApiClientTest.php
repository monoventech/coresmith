<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Exceptions\InvalidRouteConfigException;
use App\Models\Application;
use App\Models\Server;
use App\Services\Caddy\CaddyApiClient;
use App\Services\Caddy\CaddyInstaller;
use App\Services\Caddy\CaddyRouteBuilder;
use App\Services\Caddy\RouteConfigValidator;
use App\Services\SSH\SSHConnection;
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
        ->and($commands[1])->toContain('> ' . CaddyInstaller::CONFIG_FILE);
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
