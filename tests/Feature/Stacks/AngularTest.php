<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Models\Application;
use App\Services\Runtime\JsTooling;
use App\Services\Runtime\RepositoryFacts;
use App\Services\Runtime\RepositoryProbe;

function angularFacts(array $projects, array $dependencies = [], array $scripts = ['build' => 'ng build']): RepositoryFacts
{
    $package = ['name' => 'shop', 'scripts' => ['start' => 'ng serve', ...$scripts], 'dependencies' => ['@angular/core' => '^20.1.0', ...$dependencies]];

    return new RepositoryFacts([
        'package.json' => (string) json_encode($package),
        'package-lock.json' => '{}',
        'angular.json' => (string) json_encode(['version' => 1, 'projects' => $projects]),
    ], ['package-lock.json'], complete: true);
}

function angularApp(string $builder = '@angular/build:application', array $options = ['outputPath' => 'dist/shop']): array
{
    return ['projectType' => 'application', 'architect' => ['build' => ['builder' => $builder, 'options' => $options]]];
}

test('the probe reads angular.json', function () {
    expect(RepositoryProbe::FILES)->toContain('angular.json');
});

test('an Angular app without server-side rendering is a static site, served from where its builder writes', function (array $project, string $web) {
    $proposal = ApplicationType::Js->language()->detect(angularFacts(['shop' => $project]));

    expect($proposal['type'])->toBe('static')
        ->and($proposal['web'])->toBe($web)
        ->and($proposal['sources']['type'])->toBe('From angular.json, where shop builds to ' . ltrim($web, '/'));
})->with([
    'the application builder' => [angularApp(), '/dist/shop/browser'],
    'the older browser builder' => [angularApp('@angular-devkit/build-angular:browser'), '/dist/shop'],
    'an output path with its own browser folder' => [angularApp(options: ['outputPath' => ['base' => 'build/web', 'browser' => '']]), '/build/web'],
    'no output path' => [angularApp(options: []), '/dist/shop/browser'],
]);

test('an Angular app with server-side rendering runs its server from the serve:ssr script Angular writes', function () {
    $facts = angularFacts(['shop' => angularApp(options: ['outputPath' => 'dist/shop', 'ssr' => ['entry' => 'src/server.ts']])], ['@angular/ssr' => '^20.1.0'], ['build' => 'ng build', 'serve:ssr:shop' => 'node dist/shop/server/server.mjs']);
    $proposal = ApplicationType::Js->language()->detect($facts);
    $application = new Application(['type' => ApplicationType::Js, 'framework' => 'angular', 'js_runtime' => 'node', 'node_version' => '24.15.0']);
    $application->stack = ['tooling' => JsTooling::for($application, $facts)?->toArray()];

    expect($proposal['framework'])->toBe('angular')
        ->and($proposal['sources']['framework'])->toBe('@angular/ssr in package.json, serving shop')
        ->and(ApplicationType::Js->language()->webCommand($application))->toBe('/opt/asdf-data/installs/nodejs/24.15.0/bin/npm run serve:ssr:shop')
        ->and(ApplicationType::Js->language()->checks($application, 'angular', $facts, null))->toBe([]);
});

test('an Angular server without a serve:ssr script starts its server bundle directly, never ng serve', function () {
    $facts = angularFacts(['shop' => angularApp(options: ['outputPath' => 'dist/shop', 'ssr' => ['entry' => 'src/server.ts']])], ['@angular/ssr' => '^20.1.0']);
    $application = new Application(['type' => ApplicationType::Js, 'framework' => 'angular', 'js_runtime' => 'node', 'node_version' => '24.15.0']);
    $application->stack = ['tooling' => JsTooling::for($application, $facts)?->toArray()];

    expect(ApplicationType::Js->language()->webCommand($application))->toBe('/opt/asdf-data/installs/nodejs/24.15.0/bin/node dist/shop/server/server.mjs')
        ->and(ApplicationType::Js->language()->checks($application, 'angular', $facts, null))->toBe([]);
});

test('an Angular workspace with more than one application leaves the framework open, naming them', function () {
    $proposal = ApplicationType::Js->language()->detect(angularFacts(['shop' => angularApp(), 'admin' => angularApp(), 'ui' => ['projectType' => 'library']]));

    expect($proposal['framework'])->toBeNull()
        ->and($proposal['unsettled']['framework'])->toBe("angular.json has more than one application (shop and admin), so Coresmith can't tell which one to deploy.");
});

test('an Angular app whose build script does not run ng build is not taken for one', function () {
    expect(ApplicationType::Js->language()->detect(angularFacts(['shop' => angularApp()], scripts: ['build' => 'webpack']))['unsettled']['framework'])
        ->toBe("@angular/core is in package.json, but the build script doesn't run ng build, so Coresmith can't tell this is an Angular app.");
});

test('a stored start script is kept only when it is a known name or an Angular serve:ssr script with a plain project name', function (string $script, string $kept) {
    expect(JsTooling::fromArray(['manager' => 'npm', 'locked' => true, 'build' => true, 'start' => true, 'start_script' => $script])?->startScript)->toBe($kept);
})->with([
    'start:prod' => ['start:prod', 'start:prod'],
    'an Angular server' => ['serve:ssr:shop-web.v2', 'serve:ssr:shop-web.v2'],
    'a command hidden in the name' => ['serve:ssr:shop; rm -rf /', 'start'],
    'any other script' => ['deploy', 'start'],
]);
