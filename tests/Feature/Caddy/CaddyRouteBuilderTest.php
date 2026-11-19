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
        ->and($subroutes[2]['handle'])->toBe([['handler' => 'file_server', 'root' => '/var/coresmith/apps/my-app/previous/public']])
        ->and($subroutes[3]['handle'][0]['status_code'])->toBe(308)
        ->and($subroutes[4]['handle'][0])->toBe(['handler' => 'rewrite', 'uri' => '{http.matchers.file.relative}'])
        ->and($subroutes[5]['match'])->toBe([['path' => ['*.php']]])
        ->and($subroutes[6]['handle'])->toBe([['handler' => 'file_server']]);
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

test('a static site answers clean addresses from page.html or page/index.html, and missing pages with its own 404.html', function () {
    $route = (new CaddyRouteBuilder)->build(phpApp(['type' => ApplicationType::Static, 'web_directory' => '/dist']));
    $subroutes = collect($route['handle'][0]['routes']);
    $pages = $subroutes->first(fn(array $subroute): bool => ($subroute['match'][0]['file']['try_files'] ?? null) === ['{http.request.uri.path}', '{http.request.uri.path}.html', '{http.request.uri.path}/index.html']);
    $missing = $subroutes->first(fn(array $subroute): bool => ($subroute['match'][0]['file']['try_files'] ?? null) === ['/404.html']);

    expect($pages['handle'])->toBe([['handler' => 'rewrite', 'uri' => '{http.matchers.file.relative}'], ['handler' => 'file_server']])
        ->and($pages['terminal'])->toBeTrue()
        ->and($missing['handle'])->toBe([['handler' => 'rewrite', 'uri' => '/404.html'], ['handler' => 'file_server', 'status_code' => 404]])
        ->and($missing['terminal'])->toBeTrue()
        ->and($subroutes->search($pages))->toBeLessThan($subroutes->search($missing))
        ->and($subroutes->last()['handle'])->toBe([['handler' => 'file_server']]);
});

function defaultHeader(string $name, string $value): array
{
    return ['handler' => 'headers', 'response' => ['require' => ['headers' => [$name => null]], 'set' => [$name => [$value]]]];
}

test('each security header is only a default, set when the app response does not send its own', function () {
    $headers = collect(builtHandlers((new CaddyRouteBuilder)->build(phpApp())))->where('handler', 'headers')->values()->all();

    expect($headers)->toBe([
        defaultHeader('X-Frame-Options', 'SAMEORIGIN'),
        defaultHeader('X-Content-Type-Options', 'nosniff'),
        defaultHeader('X-XSS-Protection', '1; mode=block'),
        defaultHeader('Referrer-Policy', 'strict-origin-when-cross-origin'),
        defaultHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()'),
        defaultHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains; preload'),
        ['handler' => 'headers', 'response' => ['delete' => ['Server', 'X-Powered-By'], 'deferred' => true]],
    ]);
});

test('a default header reaches caddy as the json caddy adapts header ?Name value to', function () {
    $json = json_encode((new CaddyRouteBuilder)->build(phpApp(['type' => ApplicationType::Ruby])), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

    expect($json)->toContain('{"handler":"headers","response":{"require":{"headers":{"Strict-Transport-Security":null}},"set":{"Strict-Transport-Security":["max-age=31536000; includeSubDomains; preload"]}}}')
        ->and($json)->toContain('{"handler":"headers","response":{"require":{"headers":{"X-Frame-Options":null}},"set":{"X-Frame-Options":["SAMEORIGIN"]}}}');
});

test('the defaults are in place before the app answers, so caddy can see what the app sent', function (ApplicationType $type) {
    $handlers = collect(builtHandlers((new CaddyRouteBuilder)->build(phpApp(['type' => $type]))));
    $lastDefault = $handlers->keys()->filter(fn(int $index): bool => isset($handlers[$index]['response']['require']))->last();
    $answer = $handlers->keys()->first(fn(int $index): bool => in_array($handlers[$index]['handler'], ['reverse_proxy', 'file_server'], true));

    expect($lastDefault)->not->toBeNull()
        ->and($answer)->not->toBeNull()
        ->and($lastDefault)->toBeLessThan($answer);
})->with([ApplicationType::Php, ApplicationType::Js, ApplicationType::Ruby, ApplicationType::Static]);

test('a subdirectory app resolves its document root', function () {
    $app = phpApp(['directory' => '/backend', 'web_directory' => '/public']);

    expect((new CaddyRouteBuilder)->documentRoot($app))
        ->toBe('/var/coresmith/apps/my-app/current/backend/public');
});

test('a generated route passes validation', function (array $attributes) {
    $app = phpApp($attributes);

    (new RouteConfigValidator)->validate((new CaddyRouteBuilder)->build($app), $app);
})->with([
    'php' => [[]],
    'js' => [['type' => ApplicationType::Js]],
    'ruby' => [['type' => ApplicationType::Ruby]],
    'static' => [['type' => ApplicationType::Static]],
    'maintenance page' => [['toggles' => ['maintenance_mode' => true]]],
])->throwsNoExceptions();

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

test('a static site or single-page app has browsers check its pages again on every visit, unless the page sets its own caching', function (array $attributes) {
    $handlers = collect(builtHandlers((new CaddyRouteBuilder)->build(phpApp($attributes))));

    expect($handlers->filter(fn(array $handler): bool => $handler === CaddyRouteBuilder::REVALIDATE_PAGES)->count())->toBe(1)
        ->and(CaddyRouteBuilder::REVALIDATE_PAGES['response']['require']['headers'])->toBe(['Cache-Control' => null, 'Content-Type' => ['text/html*']])
        ->and(CaddyRouteBuilder::REVALIDATE_PAGES['response']['set'])->toBe(['Cache-Control' => ['no-cache']]);
})->with([
    'static site' => [['type' => ApplicationType::Static, 'web_directory' => '/dist']],
    'single-page app' => [['type' => ApplicationType::Js, 'framework' => 'svelte', 'js_runtime' => 'node', 'node_version' => '24.11.1', 'base_port' => 8100, 'web_directory' => '/dist']],
]);

test('apps that answer with their own server keep their own page caching', function (ApplicationType $type) {
    $handlers = collect(builtHandlers((new CaddyRouteBuilder)->build(phpApp(['type' => $type]))));

    expect($handlers->contains(CaddyRouteBuilder::REVALIDATE_PAGES))->toBeFalse();
})->with([ApplicationType::Php, ApplicationType::Js, ApplicationType::Ruby]);

test('the page caching default reaches caddy before the files are served', function () {
    $handlers = collect(builtHandlers((new CaddyRouteBuilder)->build(phpApp(['type' => ApplicationType::Static]))));

    expect($handlers->search(CaddyRouteBuilder::REVALIDATE_PAGES))->toBeLessThan($handlers->search(fn(array $handler): bool => $handler['handler'] === 'file_server'))
        ->and(json_encode(CaddyRouteBuilder::REVALIDATE_PAGES, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))
        ->toBe('{"handler":"headers","response":{"require":{"headers":{"Cache-Control":null,"Content-Type":["text/html*"]}},"set":{"Cache-Control":["no-cache"]}}}');
});

function previousFilesRoute(array $route): ?array
{
    return collect($route['handle'][0]['routes'])->first(fn(array $subroute): bool => ($subroute['handle'][0]['root'] ?? null) !== null && str_contains((string) $subroute['handle'][0]['root'], '/previous'));
}

test('built files missing from the live release are served from the release before it', function (array $attributes, string $root) {
    $route = (new CaddyRouteBuilder)->build(phpApp($attributes));
    $previous = previousFilesRoute($route);

    expect($previous)->toBe([
        'match' => [[
            'path' => CaddyRouteBuilder::BUILT_FILES,
            'not' => [['file' => ['try_files' => ['{http.request.uri.path}']]]],
            'file' => ['root' => $root, 'try_files' => ['{http.request.uri.path}']],
        ]],
        'handle' => [['handler' => 'file_server', 'root' => $root]],
        'terminal' => true,
    ]);
})->with([
    'a php app' => [[], '/var/coresmith/apps/my-app/previous/public'],
    'a static site' => [['type' => ApplicationType::Static, 'web_directory' => '/dist'], '/var/coresmith/apps/my-app/previous/dist'],
    'a single-page app' => [['type' => ApplicationType::Js, 'framework' => 'svelte', 'js_runtime' => 'node', 'node_version' => '24.11.1', 'base_port' => 8100, 'web_directory' => '/dist'], '/var/coresmith/apps/my-app/previous/dist'],
]);

test('the release before only fills in for built files, after the live release has had its turn and before any page fallback', function () {
    $staticRoute = (new CaddyRouteBuilder)->build(phpApp(['type' => ApplicationType::Static, 'web_directory' => '/dist']));
    $spaRoute = (new CaddyRouteBuilder)->build(phpApp(['slug' => 'spa-app', 'domain' => 'spa.example.com', 'type' => ApplicationType::Js, 'framework' => 'svelte', 'js_runtime' => 'node', 'node_version' => '24.11.1', 'base_port' => 8100, 'web_directory' => '/dist']));
    $static = collect($staticRoute['handle'][0]['routes']);
    $spa = collect($spaRoute['handle'][0]['routes']);
    $pages = $static->search(fn(array $subroute): bool => ($subroute['match'][0]['file']['try_files'] ?? null) === ['{http.request.uri.path}', '{http.request.uri.path}.html', '{http.request.uri.path}/index.html']);
    $missing = $static->search(fn(array $subroute): bool => ($subroute['match'][0]['file']['try_files'] ?? null) === ['/404.html']);
    $index = $spa->search(fn(array $subroute): bool => in_array('/index.html', $subroute['match'][0]['file']['try_files'] ?? [], true));

    expect(CaddyRouteBuilder::BUILT_FILES)->not->toContain('*.html')->not->toContain('*.php')
        ->and($static->search(previousFilesRoute($staticRoute)))->toBeGreaterThan($pages)->toBeLessThan($missing)
        ->and($spa->search(previousFilesRoute($spaRoute)))->toBeLessThan($index);
});

test('an app that answers from its own server keeps serving its own files', function () {
    expect(previousFilesRoute((new CaddyRouteBuilder)->build(phpApp(['type' => ApplicationType::Js, 'base_port' => 8100]))))->toBeNull();
});
