<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Models\Application;
use App\Services\Runtime\RepositoryFacts;
use App\Services\Runtime\RepositoryProbe;

function adonisFacts(string $build = 'node ace build', array $markers = ['ace.js']): RepositoryFacts
{
    $package = ['name' => 'shelf', 'type' => 'module', 'scripts' => ['build' => $build, 'start' => 'node bin/server.js'], 'dependencies' => ['@adonisjs/core' => '^6.17.0']];

    return new RepositoryFacts(['package.json' => (string) json_encode($package), 'package-lock.json' => '{}'], ['package-lock.json'], markers: $markers, complete: true);
}

function adonisApp(array $attributes = []): Application
{
    return new Application(['type' => ApplicationType::Js, 'framework' => 'adonisjs', 'js_runtime' => 'node', 'node_version' => '24.15.0', 'base_port' => 4300, 'stack' => ['tooling' => ['manager' => 'npm', 'lockfile' => true, 'build' => true, 'start' => true]], ...$attributes]);
}

test('the probe looks for ace.js, which only an AdonisJS 6 app has at its root', function () {
    expect(RepositoryProbe::MARKERS)->toContain('ace.js');
});

test('a package.json with @adonisjs/core and an ace.js is an AdonisJS app', function () {
    $proposal = ApplicationType::Js->language()->detect(adonisFacts());

    expect($proposal['type'])->toBe('js')
        ->and($proposal['framework'])->toBe('adonisjs')
        ->and($proposal['sources']['framework'])->toBe('@adonisjs/core in package.json, with ace.js')
        ->and($proposal['unsettled'])->toBe([]);
});

test('an AdonisJS app whose build script does not run node ace build is not taken for one', function () {
    $proposal = ApplicationType::Js->language()->detect(adonisFacts('tsc'));

    expect($proposal['framework'])->toBeNull()
        ->and($proposal['unsettled']['framework'])->toBe("@adonisjs/core is in package.json, but the build script doesn't run node ace build, so Coresmith can't tell this is an AdonisJS app.");
});

test('without ace.js, @adonisjs/core alone is left to the start script, as before', function () {
    expect(ApplicationType::Js->language()->detect(adonisFacts(markers: []))['framework'])->not->toBe('adonisjs');
});

test('an AdonisJS app starts from its build, not from the start script meant for inside it', function () {
    expect(ApplicationType::Js->language()->webCommand(adonisApp()))->toBe('/opt/asdf-data/installs/nodejs/24.15.0/bin/node build/bin/server.js');
});

test('an AdonisJS app gets an APP_KEY when it is added, and runs with a log level and a session driver it can change', function () {
    expect(array_keys(ApplicationType::Js->language()->initialEnvironment('adonisjs')))->toBe(['APP_KEY'])
        ->and(strlen(ApplicationType::Js->language()->initialEnvironment('adonisjs')['APP_KEY']))->toBe(32)
        ->and(ApplicationType::Js->language()->processEnvironment(adonisApp()))->toBe(['NODE_ENV' => 'production', 'LOG_LEVEL' => 'info', 'SESSION_DRIVER' => 'cookie']);
});

test('an AdonisJS app is not asked for a start script', function () {
    expect(ApplicationType::Js->language()->checks(adonisApp(), 'adonisjs', adonisFacts(), null))->toBe([]);
});
