<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Models\Application;
use App\Services\Caddy\CaddyRouteBuilder;
use App\Services\Caddy\RouteConfigValidator;
use App\Services\Panel\Reverb;

function reverbRouteApp(bool $live, array $attributes = []): Application
{
    return Application::factory()->create([
        'slug' => 'chat',
        'domain' => 'chat.example.com',
        'type' => ApplicationType::Php,
        'framework' => 'laravel',
        'base_port' => 8200,
        'directory' => '/',
        'web_directory' => '/public',
        'env_variables' => "REVERB_APP_ID=chat-app\nREVERB_APP_KEY=chat-key\nREVERB_APP_SECRET=s3cret\n",
        'toggles' => ['reverb' => true],
        'processes' => $live ? [['name' => 'reverb', 'command' => Reverb::COMMAND . '8202']] : null,
        ...$attributes,
    ]);
}

function reverbRoutes(Application $application): array
{
    return (new CaddyRouteBuilder)->build($application)['handle'][0]['routes'];
}

function reverbRouted(Application $application): array
{
    return array_values(array_filter(reverbRoutes($application), fn(array $route): bool => str_starts_with((string) ($route['match'][0]['path'][0] ?? ''), '/app')));
}

test('a live reverb gets a websocket route and an api route before the app', function () {
    $application = reverbRouteApp(true);
    $routes = reverbRoutes($application);
    $proxy = [['handler' => 'reverse_proxy', 'upstreams' => [['dial' => '127.0.0.1:8202']]]];

    expect($routes[2])->toBe(['match' => [['path' => ['/app/chat-key'], 'header' => ['Upgrade' => ['websocket']]]], 'handle' => $proxy])
        ->and($routes[3])->toBe(['match' => [['path' => ['/apps/chat-app/*']]], 'handle' => $proxy])
        ->and($routes[1]['handle'][0]['handler'])->toBe('vars')
        ->and($routes[4]['handle'][0]['status_code'] ?? null)->toBe(308);

    (new RouteConfigValidator)->validate((new CaddyRouteBuilder)->build($application), $application);
});

test('no reverb routes while it waits for a deploy or is off', function (bool $live, array $toggles) {
    expect(reverbRouted(reverbRouteApp($live, ['toggles' => $toggles])))->toBe([]);
})->with([
    'on, waiting for a deploy' => [false, ['reverb' => true]],
    'off, with a stale process' => [true, ['reverb' => false]],
    'never set' => [false, []],
]);

test('a key that is not path safe is never routed', function (string $env) {
    expect(reverbRouted(reverbRouteApp(true, ['env_variables' => $env])))->toBe([]);
})->with([
    'a slash in the key' => ["REVERB_APP_ID=chat-app\nREVERB_APP_KEY=chat/../key\nREVERB_APP_SECRET=s3cret\n"],
    'a space in the id' => ["REVERB_APP_ID=\"chat app\"\nREVERB_APP_KEY=chat-key\nREVERB_APP_SECRET=s3cret\n"],
    'no secret' => ["REVERB_APP_ID=chat-app\nREVERB_APP_KEY=chat-key\n"],
]);

test('an app in maintenance serves no reverb route', function () {
    expect(reverbRouted(reverbRouteApp(true, ['toggles' => ['reverb' => true, 'maintenance_mode' => true]])))->toBe([]);
});
