<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Exceptions\InvalidRouteConfigException;
use App\Exceptions\PortExhaustedException;
use App\Models\Application;
use App\Models\Server;
use App\Services\Caddy\CaddyRouteBuilder;
use App\Services\Caddy\RouteConfigValidator;
use App\Services\Server\PortAllocator;

test('the first application on a server gets the start of the range', function () {
    expect((new PortAllocator)->allocate(Server::factory()->create()))->toBe(8000);
});

test('each application gets its own block of ten', function () {
    $server = Server::factory()->create();
    $allocator = new PortAllocator;

    Application::factory()->for($server)->create(['base_port' => $allocator->allocate($server)]);
    $second = $allocator->allocate($server->fresh());

    expect($second)->toBe(8010);
});

test('a freed block in the middle is reused', function () {
    $server = Server::factory()->create();
    Application::factory()->for($server)->create(['base_port' => 8000]);
    Application::factory()->for($server)->create(['base_port' => 8020]);

    expect((new PortAllocator)->allocate($server->fresh()))->toBe(8010);
});

test('a soft deleted application still holds its block', function () {
    $server = Server::factory()->create();
    Application::factory()->for($server)->create(['base_port' => 8000])->delete();

    expect((new PortAllocator)->allocate($server->fresh()))->toBe(8010);
});

test('applications on another server do not consume blocks', function () {
    $a = Server::factory()->create();
    $b = Server::factory()->create();
    Application::factory()->for($a)->create(['base_port' => 8000]);

    expect((new PortAllocator)->allocate($b))->toBe(8000);
});

test('the server supports two hundred applications', function () {
    expect(PortAllocator::maximumApplications())->toBe(200);
});

test('a full server refuses to allocate', function () {
    $server = Server::factory()->create();

    for ($port = 8000; $port <= 9990; $port += 10) {
        Application::factory()->for($server)->create(['base_port' => $port]);
    }

    (new PortAllocator)->allocate($server->fresh());
})->throws(PortExhaustedException::class);

test('ssr ports come from their own range', function () {
    expect((new PortAllocator)->allocateSsrPort(Server::factory()->create()))->toBe(13700);
});

test('an allocated ssr port is not handed out twice', function () {
    $server = Server::factory()->create();
    Application::factory()->for($server)->create(['ssr_port' => 13700]);

    expect((new PortAllocator)->allocateSsrPort($server->fresh()))->toBe(13701);
});

test('only ports inside the allocatable range are considered valid', function () {
    expect(PortAllocator::isInAllocatableRange(8000))->toBeTrue()
        ->and(PortAllocator::isInAllocatableRange(9999))->toBeTrue()
        ->and(PortAllocator::isInAllocatableRange(7999))->toBeFalse()
        ->and(PortAllocator::isInAllocatableRange(10000))->toBeFalse()
        ->and(PortAllocator::isInAllocatableRange(22))->toBeFalse();
});

test('a tampered base_port cannot open a route to a privileged service', function (int $tamperedBasePort, int $upstreamPort) {
    $app = Application::factory()->create([
        'slug' => 'my-app',
        'domain' => 'app.example.com',
        'type' => ApplicationType::Js,
        'base_port' => $tamperedBasePort,
    ]);

    $config = (new CaddyRouteBuilder)->build($app);
    $config['handle'][0]['routes'][1]['handle'][2]['upstreams'] = [['dial' => '127.0.0.1:' . $upstreamPort]];

    (new RouteConfigValidator)->validate($config, $app);
})->with([
    [22, 22],
    [25, 25],
    [3306, 3306],
    [6379, 6379],
    [2019, 2019],
])->throws(InvalidRouteConfigException::class);
