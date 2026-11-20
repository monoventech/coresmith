<?php

declare(strict_types=1);

use App\Models\Server;
use App\Models\ServerFrontDoor;
use App\Services\Caddy\CaddyApiClient;
use App\Services\Caddy\CaddyRouteBuilder;
use App\Services\Caddy\RouteConfigValidator;
use App\Services\SSH\SSHConnection;
use Tests\Support\FakeSSHTransport;

function frontDoorCaddyRow(): ServerFrontDoor
{
    $door = Server::factory()->create();

    return ServerFrontDoor::query()->create([
        'server_id' => Server::factory()->create(['team_id' => $door->team_id])->id,
        'door_server_id' => $door->id,
        'state' => ServerFrontDoor::OPEN,
        'subnet' => '10.205.2.0/24',
        'door_address' => '10.205.2.1',
        'home_address' => '10.205.2.2',
    ]);
}

function frontDoorCaddyConnection(FakeSSHTransport $transport, bool $runsCoresmith = false): SSHConnection
{
    return (new SSHConnection(Server::factory()->create(['host_key_fingerprint' => null, 'os_name' => 'ubuntu', 'runs_coresmith' => $runsCoresmith]), $transport))->connect();
}

function frontDoorCaddyClient(): CaddyApiClient
{
    return new CaddyApiClient(new CaddyRouteBuilder, new RouteConfigValidator);
}

test("the door route sends certificate checks to port 80 and everything else to the home server's HTTPS by name", function () {
    $frontDoor = frontDoorCaddyRow();

    expect((new CaddyRouteBuilder)->door($frontDoor, ['beelink.example.com', 'notes.example.com']))->toBe([
        '@id' => "door-{$frontDoor->server_id}",
        'match' => [['host' => ['beelink.example.com', 'notes.example.com']]],
        'handle' => [[
            'handler' => 'subroute',
            'routes' => [
                [
                    'match' => [['path' => ['/.well-known/acme-challenge/*']]],
                    'handle' => [['handler' => 'reverse_proxy', 'upstreams' => [['dial' => '10.205.2.2:80']]]],
                    'terminal' => true,
                ],
                [
                    'handle' => [[
                        'handler' => 'reverse_proxy',
                        'upstreams' => [['dial' => '10.205.2.2:443']],
                        'transport' => ['protocol' => 'http', 'tls' => ['insecure_skip_verify' => true, 'server_name' => '{http.request.host}']],
                    ]],
                ],
            ],
        ]],
        'terminal' => true,
    ]);
});

test('a new door route goes first on a server that only runs apps, with no certificate policy', function () {
    $frontDoor = frontDoorCaddyRow();
    $transport = (new FakeSSHTransport)->respondTo("-X GET 'http://localhost:2019/id/door-", 'curl: (22) The requested URL returned error: 404', 22);

    frontDoorCaddyClient()->putDoor(frontDoorCaddyConnection($transport), $frontDoor, ['notes.example.com']);

    expect(implode("\n", $transport->commands()))
        ->toContain("-X PUT 'http://localhost:2019/config/apps/http/servers/coresmith/routes/0'")
        ->toContain('"host":["notes.example.com"]')
        ->not->toContain('/config/apps/tls/automation/policies');
});

test("on Coresmith's own machine the door route goes after Coresmith's site with its own certificate policy", function () {
    $frontDoor = frontDoorCaddyRow();
    $transport = (new FakeSSHTransport)->respondTo("-X GET 'http://localhost:2019/id/door-", 'curl: (22) The requested URL returned error: 404', 22);

    frontDoorCaddyClient()->putDoor(frontDoorCaddyConnection($transport, runsCoresmith: true), $frontDoor, ['notes.example.com']);

    expect(implode("\n", $transport->commands()))
        ->toContain("-X PUT 'http://localhost:2019/config/apps/http/servers/coresmith/routes/1'")
        ->toContain("-X PUT 'http://localhost:2019/config/apps/tls/automation/policies/0'")
        ->toContain('"@id":"door-' . $frontDoor->server_id . '-tls","subjects":["notes.example.com"]');
});

test('a door route Caddy already has is replaced in place', function () {
    $frontDoor = frontDoorCaddyRow();
    $transport = new FakeSSHTransport;

    frontDoorCaddyClient()->putDoor(frontDoorCaddyConnection($transport), $frontDoor, ['notes.example.com']);

    expect(implode("\n", $transport->commands()))
        ->toContain("-X PATCH 'http://localhost:2019/id/door-{$frontDoor->server_id}'")
        ->not->toContain('/routes/0');
});

test('removing a door route takes its certificate policy with it on Coresmith\'s own machine', function () {
    $frontDoor = frontDoorCaddyRow();
    $transport = new FakeSSHTransport;

    frontDoorCaddyClient()->removeDoor(frontDoorCaddyConnection($transport, runsCoresmith: true), $frontDoor);

    expect(implode("\n", $transport->commands()))
        ->toContain("-X DELETE 'http://localhost:2019/id/door-{$frontDoor->server_id}'")
        ->toContain("-X DELETE 'http://localhost:2019/id/door-{$frontDoor->server_id}-tls'");
});

test("a home server trusts its door's WireGuard address for the visitor's address, and nothing else", function () {
    $transport = new FakeSSHTransport;

    frontDoorCaddyClient()->trustProxies(frontDoorCaddyConnection($transport), ['10.205.2.1/32'], ['X-Forwarded-For']);

    expect(implode("\n", $transport->commands()))
        ->toContain("-X PUT 'http://localhost:2019/config/apps/http/servers/coresmith/trusted_proxies'")
        ->toContain('{"source":"static","ranges":["10.205.2.1/32"]}')
        ->toContain('["X-Forwarded-For"]')
        ->not->toContain('Cf-Connecting-Ip');
});

test('the tunnel still trusts loopback through the general call', function () {
    $on = new FakeSSHTransport;
    $off = new FakeSSHTransport;

    frontDoorCaddyClient()->trustLoopback(frontDoorCaddyConnection($on), true);
    frontDoorCaddyClient()->trustLoopback(frontDoorCaddyConnection($off), false);

    expect(implode("\n", $on->commands()))
        ->toContain('{"source":"static","ranges":["127.0.0.1/32","::1/128"]}')
        ->toContain('["Cf-Connecting-Ip","X-Forwarded-For"]')
        ->and(implode("\n", $off->commands()))
        ->toContain("-X DELETE 'http://localhost:2019/config/apps/http/servers/coresmith/trusted_proxies'")
        ->not->toContain('-X PUT');
});
