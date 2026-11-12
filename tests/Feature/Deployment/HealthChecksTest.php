<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Models\Application;
use App\Services\Deployment\DeploymentCommands;
use App\Services\Deployment\ReleasePaths;
use App\Services\Runtime\AsdfManager;

function healthChecksFor(array $attributes): array
{
    $application = new Application(array_merge(['slug' => 'my-app'], $attributes));
    $paths = new ReleasePaths($application);

    return (new DeploymentCommands(new AsdfManager))->healthChecks($application, $paths, $paths->release('20260913-140509'));
}

test('a php release must have an index file in its web root', function (array $attributes, array $expected) {
    expect(healthChecksFor(['type' => ApplicationType::Php, ...$attributes]))->toBe($expected);
})->with([
    'public web root' => [
        ['directory' => '/', 'web_directory' => '/public'],
        ['public/index.php' => "test -f '/var/coresmith/apps/my-app/releases/20260913-140509/public/index.php'"],
    ],
    'release root' => [
        ['directory' => '/', 'web_directory' => '/'],
        ['index.php' => "test -f '/var/coresmith/apps/my-app/releases/20260913-140509/index.php'"],
    ],
    'app in a subdirectory' => [
        ['directory' => '/api', 'web_directory' => '/public'],
        ['public/index.php' => "test -f '/var/coresmith/apps/my-app/releases/20260913-140509/api/public/index.php'"],
    ],
]);

test('a static release must have its web directory', function (array $attributes, array $expected) {
    expect(healthChecksFor(['type' => ApplicationType::Static, ...$attributes]))->toBe($expected);
})->with([
    'dist directory' => [
        ['directory' => '/', 'web_directory' => '/dist'],
        ['dist/' => "test -d '/var/coresmith/apps/my-app/releases/20260913-140509/dist'"],
    ],
    'release root' => [
        ['directory' => '/', 'web_directory' => '/'],
        ['the release root' => "test -d '/var/coresmith/apps/my-app/releases/20260913-140509'"],
    ],
]);

test('a javascript release must have a package manifest', function (string $directory, string $path) {
    expect(healthChecksFor(['type' => ApplicationType::Js, 'directory' => $directory, 'web_directory' => '/public']))
        ->toBe(['package.json' => "test -f '{$path}'"]);
})->with([
    'at the root' => ['/', '/var/coresmith/apps/my-app/releases/20260913-140509/package.json'],
    'in a subdirectory' => ['/frontend/', '/var/coresmith/apps/my-app/releases/20260913-140509/frontend/package.json'],
]);

test('a ruby release must have a Gemfile', function () {
    expect(healthChecksFor(['type' => ApplicationType::Ruby, 'directory' => '/', 'web_directory' => '/public']))
        ->toBe(['Gemfile' => "test -f '/var/coresmith/apps/my-app/releases/20260913-140509/Gemfile'"]);
});

test('health check paths are shell quoted', function () {
    $checks = healthChecksFor(['type' => ApplicationType::Php, 'directory' => "/it's", 'web_directory' => '/public']);

    expect(array_values($checks)[0])->toBe("test -f '/var/coresmith/apps/my-app/releases/20260913-140509/it'\\''s/public/index.php'");
});

test('a Deno app is healthy with deno.json, deno.jsonc or package.json', function () {
    expect(healthChecksFor(['type' => ApplicationType::Js, 'js_runtime' => 'deno', 'directory' => '/']))->toBe([
        'deno.json, deno.jsonc or package.json' => "test -f '/var/coresmith/apps/my-app/releases/20260913-140509/deno.json' || test -f '/var/coresmith/apps/my-app/releases/20260913-140509/deno.jsonc' || test -f '/var/coresmith/apps/my-app/releases/20260913-140509/package.json'",
    ]);
});
