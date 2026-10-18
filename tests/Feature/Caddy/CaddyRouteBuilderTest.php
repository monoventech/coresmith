<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Exceptions\InvalidRouteConfigException;
use App\Models\Application;
use App\Services\Caddy\CaddyRouteBuilder;
use App\Services\Caddy\RouteConfigValidator;
use App\Services\Caddy\SensitivePaths;

function phpApp(array $attributes = []): Application
{
    return Application::factory()->create(array_merge([
        'slug' => 'my-app',
        'domain' => 'app.example.com',
        'type' => ApplicationType::Php,
        'base_port' => 8000,
        'directory' => '/',
        'web_directory' => '/public',
    ], $attributes));
}

function builtHandlers(array $route): array
{
    return collect($route['handle'][0]['routes'])->flatMap(fn(array $subroute): array => $subroute['handle'])->all();
}

test('the route carries a targetable id and the app domain', function () {
    $route = (new CaddyRouteBuilder)->build($app = phpApp());

    expect($route['@id'])->toBe('app-' . $app->id)
        ->and($route['match'][0]['host'])->toBe(['app.example.com'])
        ->and($route['terminal'])->toBeTrue();
});

test('the sensitive path block is the FIRST subroute', function () {
    $route = (new CaddyRouteBuilder)->build(phpApp());
    $subroutes = $route['handle'][0]['routes'];

    expect($subroutes[0]['match'][0]['path'])->toBe(SensitivePaths::PATHS)
        ->and($subroutes[0]['handle'][0]['status_code'])->toBe(404);
});

test('every credential bearing path is blocked', function (string $path) {
    expect(SensitivePaths::PATHS)->toContain($path);
})->with(['/.env*', '/.git/*', '/storage/*', '/vendor/*', '/node_modules/*', '/composer.lock']);

test('a php app is served over its own fpm socket the way caddy expands php_fastcgi', function () {
    $route = (new CaddyRouteBuilder)->build(phpApp());
    $subroutes = $route['handle'][0]['routes'];
    $handlers = collect(builtHandlers($route));
    $fastcgi = $handlers->first(fn(array $handler): bool => ($handler['transport']['protocol'] ?? null) === 'fastcgi');

    expect($handlers->firstWhere('handler', 'vars')['root'])->toBe('/var/coresmith/apps/my-app/current/public')
        ->and($fastcgi['upstreams'])->toBe([['dial' => 'unix//var/run/php/coresmith-app-my-app-fpm.sock']])
        ->and($fastcgi['transport'])->toBe(['protocol' => 'fastcgi', 'resolve_root_symlink' => true, 'split_path' => ['.php']])
        ->and($subroutes[2]['handle'][0]['status_code'])->toBe(308)
        ->and($subroutes[3]['handle'][0])->toBe(['handler' => 'rewrite', 'uri' => '{http.matchers.file.relative}'])
        ->and($subroutes[4]['match'])->toBe([['path' => ['*.php']]])
        ->and($subroutes[5]['handle'])->toBe([['handler' => 'file_server']]);
});

test('a js app reverse proxies to its allocated loopback port', function () {
    $route = (new CaddyRouteBuilder)->build(phpApp(['type' => ApplicationType::Js, 'base_port' => 8100]));
    $proxy = collect(builtHandlers($route))->firstWhere('handler', 'reverse_proxy');

    expect($proxy['upstreams'])->toBe([['dial' => '127.0.0.1:8100']])
        ->and(collect(builtHandlers($route))->firstWhere('handler', 'vars'))->toBeNull();
});

test('a static app is served by the file server only', function () {
    $handlers = collect(builtHandlers((new CaddyRouteBuilder)->build(phpApp(['type' => ApplicationType::Static]))));

    expect($handlers->firstWhere('handler', 'file_server'))->not->toBeNull()
        ->and($handlers->firstWhere('handler', 'vars')['root'])->toBe('/var/coresmith/apps/my-app/current/public')
        ->and($handlers->firstWhere('handler', 'reverse_proxy'))->toBeNull();
});

test('security headers are set up front and the fingerprint headers are deleted as the response is written', function () {
    $headers = collect(builtHandlers((new CaddyRouteBuilder)->build(phpApp())))->where('handler', 'headers')->values();

    expect($headers[0]['response']['set']['X-Content-Type-Options'])->toBe(['nosniff'])
        ->and($headers[0]['response']['set']['X-Frame-Options'])->toBe(['SAMEORIGIN'])
        ->and($headers[0]['response'])->not->toHaveKey('deferred')
        ->and($headers[1]['response'])->toBe(['delete' => ['Server', 'X-Powered-By'], 'deferred' => true]);
});

test('a subdirectory app resolves its document root', function () {
    $app = phpApp(['directory' => '/backend', 'web_directory' => '/public']);

    expect((new CaddyRouteBuilder)->documentRoot($app))
        ->toBe('/var/coresmith/apps/my-app/current/backend/public');
});

test('a generated route passes validation', function () {
    $app = phpApp();

    (new RouteConfigValidator)->validate((new CaddyRouteBuilder)->build($app), $app);
})->throwsNoExceptions();

test('an upstream pointing off box is refused', function () {
    $app = phpApp(['type' => ApplicationType::Js]);
    $config = (new CaddyRouteBuilder)->build($app);
    $config['handle'][0]['routes'][2]['handle'][0]['upstreams'] = [['dial' => 'attacker.test:80']];

    (new RouteConfigValidator)->validate($config, $app);
})->throws(InvalidRouteConfigException::class);

test('an upstream outside the allocated port block is refused', function () {
    $app = phpApp(['type' => ApplicationType::Js, 'base_port' => 8100]);
    $config = (new CaddyRouteBuilder)->build($app);
    $config['handle'][0]['routes'][2]['handle'][0]['upstreams'] = [['dial' => '127.0.0.1:8200']];

    (new RouteConfigValidator)->validate($config, $app);
})->throws(InvalidRouteConfigException::class);

test('a port inside the allocated block is allowed', function () {
    $app = phpApp(['type' => ApplicationType::Js, 'base_port' => 8100]);
    $config = (new CaddyRouteBuilder)->build($app);
    $config['handle'][0]['routes'][2]['handle'][0]['upstreams'] = [['dial' => '127.0.0.1:8109']];

    (new RouteConfigValidator)->validate($config, $app);
})->throwsNoExceptions();

test('hijacking another apps domain is refused', function () {
    $app = phpApp();
    $config = (new CaddyRouteBuilder)->build($app);
    $config['match'][0]['host'] = ['victim.example.com'];

    (new RouteConfigValidator)->validate($config, $app);
})->throws(InvalidRouteConfigException::class);

test('borrowing another apps fpm socket is refused', function () {
    $app = phpApp();
    $config = (new CaddyRouteBuilder)->build($app);
    $config['handle'][0]['routes'][4]['handle'][0]['upstreams'] = [['dial' => 'unix//var/run/php/coresmith-app-victim-fpm.sock']];

    (new RouteConfigValidator)->validate($config, $app);
})->throws(InvalidRouteConfigException::class);

test('serving a root outside the app directory is refused', function (string $root) {
    $app = phpApp();
    $config = (new CaddyRouteBuilder)->build($app);
    $config['handle'][0]['routes'][1]['handle'][0]['root'] = $root;

    (new RouteConfigValidator)->validate($config, $app);
})->with([
    '/etc/coresmith/env',
    '/var/coresmith/apps/victim/current/public',
    '/var/coresmith/apps/my-app/current/../../victim/current',
])->throws(InvalidRouteConfigException::class);

test('an app with no domain cannot be routed', function () {
    $app = phpApp(['domain' => null]);

    (new RouteConfigValidator)->validate(['@id' => 'x'], $app);
})->throws(InvalidRouteConfigException::class);
