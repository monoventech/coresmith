<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Enums\PowerState;
use App\Exceptions\InvalidRouteConfigException;
use App\Models\Application;
use App\Models\Server;
use App\Services\Caddy\CaddyApiClient;
use App\Services\Caddy\CaddyInstaller;
use App\Services\Caddy\CaddyRouteBuilder;
use App\Services\Caddy\CoresmithSite;
use App\Services\Caddy\RouteConfigValidator;
use App\Services\SSH\SSHConnection;
use Tests\Support\FakeSSHTransport;

function wwwApp(array $attributes = []): Application
{
    return Application::factory()->create([
        'slug' => 'shop',
        'domain' => 'example.com',
        'additional_domains' => [],
        'type' => ApplicationType::Php,
        'base_port' => 13000,
        'directory' => '/',
        'web_directory' => '/public',
        ...$attributes,
    ]);
}

function wwwSubroutes(array $route): array
{
    return $route['handle'][0]['routes'];
}

test("an apex domain's route also answers for www, and sends it to the apex first, keeping the path and query", function () {
    $route = (new CaddyRouteBuilder)->build(wwwApp());

    expect($route['match'][0]['host'])->toBe(['example.com', 'www.example.com'])
        ->and(wwwSubroutes($route)[0])->toBe([
            'match' => [['host' => ['www.example.com']]],
            'handle' => [[
                'handler' => 'static_response',
                'status_code' => 308,
                'headers' => ['Location' => ['https://example.com{http.request.uri}']],
            ]],
            'terminal' => true,
        ]);
});

test('a www domain sends its apex to it', function () {
    $route = (new CaddyRouteBuilder)->build(wwwApp(['domain' => 'www.example.com']));

    expect($route['match'][0]['host'])->toBe(['www.example.com', 'example.com'])
        ->and(wwwSubroutes($route)[0]['handle'][0]['headers']['Location'])->toBe(['https://www.example.com{http.request.uri}']);
});

test('a subdomain gets no second name and no redirect', function () {
    $route = (new CaddyRouteBuilder)->build(wwwApp(['domain' => 'app.example.com']));

    expect($route['match'][0]['host'])->toBe(['app.example.com'])
        ->and(collect(wwwSubroutes($route))->pluck('match.0.host')->filter()->all())->toBe([]);
});

test('www still goes to the apex while the app is stopped or in maintenance', function (array $attributes) {
    $subroutes = wwwSubroutes((new CaddyRouteBuilder)->build(wwwApp($attributes)));

    expect($subroutes)->toHaveCount(2)
        ->and($subroutes[0]['match'])->toBe([['host' => ['www.example.com']]]);
})->with([
    'stopped' => [['power_state' => PowerState::Stopped]],
    'maintenance' => [['toggles' => ['maintenance_mode' => true]]],
]);

test('the route check accepts the www name Coresmith added, and still refuses any other host', function () {
    $application = wwwApp();
    $route = (new CaddyRouteBuilder)->build($application);

    (new RouteConfigValidator)->validate($route, $application);

    $route['match'][0]['host'][] = 'evil.example.net';

    expect(fn() => (new RouteConfigValidator)->validate($route, $application))->toThrow(InvalidRouteConfigException::class);
});

test("on Coresmith's machine the app's certificate policy covers the www name too", function () {
    $transport = new FakeSSHTransport;
    $server = Server::factory()->create(['host_key_fingerprint' => null, 'os_name' => 'ubuntu', 'runs_coresmith' => true, 'ip_address' => '127.0.0.1', 'meta' => ['caddy_admin' => 'socket']]);
    $application = wwwApp(['server_id' => $server->getKey(), 'team_id' => $server->team_id]);

    (new CaddyApiClient(new CaddyRouteBuilder, new RouteConfigValidator))->addSite((new SSHConnection($server, $transport))->connect(), $application);

    $policy = collect($transport->commands())->first(fn(string $command): bool => str_contains($command, "-X PUT --unix-socket /run/caddy-admin/admin.sock 'http://localhost/config/apps/tls/automation/policies/0'"));

    expect($policy)->toContain('"subjects":["example.com","www.example.com"]');
});

test("rebuilding Coresmith's machine gives each host one certificate policy, the first that names it", function () {
    $adapted = (string) file_get_contents(base_path('tests/Fixtures/Caddy/adapted-public.json'));
    $current = json_decode((new CaddyInstaller)->thisMachineConfig(CoresmithSite::fromAdapted($adapted), null), false);
    array_splice($current->apps->http->servers->coresmith->routes, 1, 0, [
        json_decode('{"@id":"app-01","match":[{"host":["example.com","www.example.com"]}],"terminal":true}'),
        json_decode('{"@id":"app-02","match":[{"host":["www.example.com","old.example.net","blog.example.org"]}],"terminal":true}'),
        json_decode('{"@id":"app-03","match":[{"host":["coresmith.example.com"]}],"terminal":true}'),
    ]);

    $config = json_decode((new CaddyInstaller)->thisMachineConfig(CoresmithSite::fromAdapted($adapted), null, (string) json_encode($current)), true);
    $policies = collect($config['apps']['tls']['automation']['policies'])->keyBy('@id');

    expect($policies->keys()->all())->toBe(['app-01-tls', 'app-02-tls', 'coresmith-site-tls', 'coresmith-any-tls'])
        ->and($policies['app-01-tls']['subjects'])->toBe(['example.com', 'www.example.com'])
        ->and($policies['app-02-tls']['subjects'])->toBe(['blog.example.org']);
});
