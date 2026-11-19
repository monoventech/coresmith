<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Models\Application;
use App\Services\Caddy\CaddyRouteBuilder;
use App\Services\Deployment\ReleasePaths;
use App\Services\Runtime\RepositoryFacts;
use App\Services\Runtime\RepositoryProbe;
use App\Stacks\JavaScript;
use App\Stacks\JavaScriptDetection;
use App\Stacks\Route;

function filesFrameworkApp(string $framework, array $attributes = []): Application
{
    return Application::factory()->make([...['slug' => 'shop', 'type' => ApplicationType::Js, 'framework' => $framework, 'js_runtime' => 'node', 'node_version' => '24.11.1', 'base_port' => 8100, 'directory' => '/', 'web_directory' => '/dist', 'domain' => 'shop.example.com'], ...$attributes]);
}

test('a React, Vue or Svelte app is served as files with every unknown path falling back to its index.html, and starts no process', function (string $framework) {
    $application = filesFrameworkApp($framework);

    expect(app(JavaScript::class)->route($application))->toBe(Route::SinglePage)
        ->and(Route::SinglePage->servesFiles())->toBeTrue()
        ->and(app(JavaScript::class)->webCommand($application))->toBeNull();
})->with(['react', 'vue', 'svelte']);

test('other JavaScript frameworks still run their server behind Caddy', function () {
    $application = filesFrameworkApp('nextjs', ['web_directory' => '/public']);

    expect(app(JavaScript::class)->route($application))->toBe(Route::ReverseProxy)
        ->and(app(JavaScript::class)->webCommand($application))->not->toBeNull();
});

test('a release of a files framework must hold its web root after the build', function () {
    $application = filesFrameworkApp('vue');
    $paths = new ReleasePaths($application);
    $checks = app(JavaScript::class)->releaseChecks($application, $paths, $paths->release('20261115-100000'));

    expect($checks)->toHaveKey('package.json')
        ->and($checks['dist/'] ?? null)->toBe("test -d '/var/coresmith/apps/shop/releases/20261115-100000/dist'");
});

test('Caddy serves the build and hands unknown paths to index.html, so client-side routes open directly', function () {
    $application = filesFrameworkApp('react');
    $application->save();
    $route = (new CaddyRouteBuilder)->build($application);
    $handlers = collect($route['handle'][0]['routes'])->flatMap(fn(array $subroute): array => $subroute['handle']);
    $fallback = collect($route['handle'][0]['routes'])->first(fn(array $subroute): bool => in_array('/index.html', $subroute['match'][0]['file']['try_files'] ?? [], true));

    expect($handlers->firstWhere('handler', 'vars')['root'])->toBe('/var/coresmith/apps/shop/current/dist')
        ->and($fallback['match'][0]['file']['try_files'])->toBe(['{http.request.uri.path}', '{http.request.uri.path}/', '/index.html'])
        ->and($fallback['handle'])->toBe([['handler' => 'rewrite', 'uri' => '{http.matchers.file.relative}']])
        ->and($handlers->firstWhere('handler', 'file_server'))->not->toBeNull()
        ->and($handlers->firstWhere('handler', 'reverse_proxy'))->toBeNull();
});

test('a React app whose vite.config sets its own outDir leaves the web root to the person', function () {
    $facts = new RepositoryFacts([
        'package.json' => (string) json_encode(['scripts' => ['build' => 'vite build'], 'dependencies' => ['react' => '^19.1.1', 'react-dom' => '^19.1.1'], 'devDependencies' => ['vite' => '^7.1.7']]),
        'package-lock.json' => '{}',
        'vite.config.ts' => "export default { build: { outDir: 'web' } }",
    ], complete: true);

    $proposal = app(JavaScriptDetection::class)->detect($facts);

    expect([$proposal['type'], $proposal['framework'], $proposal['web']])->toBe(['js', 'react', null])
        ->and($proposal['unsettled'])->toHaveKey('web_directory');
});

test('a Vite app without React, Vue or Svelte is still a static site', function () {
    $facts = new RepositoryFacts([
        'package.json' => (string) json_encode(['scripts' => ['build' => 'vite build'], 'devDependencies' => ['vite' => '^7.1.7']]),
        'bun.lock' => '',
    ], complete: true);

    expect(app(JavaScriptDetection::class)->detect($facts))->toMatchArray(['type' => 'static', 'framework' => '', 'web' => '/dist']);
});

test('a vite.config written as an ES module is read for its outDir too', function (string $config) {
    $facts = new RepositoryFacts([
        'package.json' => (string) json_encode(['scripts' => ['build' => 'vite build'], 'dependencies' => ['vue' => '^3.5.22'], 'devDependencies' => ['vite' => '^7.1.7']]),
        'bun.lock' => '',
        $config => "export default { build: { outDir: 'web' } }",
    ], complete: true);

    expect(app(JavaScriptDetection::class)->detect($facts)['unsettled'])->toHaveKey('web_directory')
        ->and(RepositoryProbe::FILES)->toContain($config);
})->with(['vite.config.mjs', 'vite.config.mts']);

test('detection reads vue.config.js, where Vue CLI sets its own outputDir', function () {
    expect(RepositoryProbe::FILES)->toContain('vue.config.js');
});
