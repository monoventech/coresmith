<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Models\Application;
use App\Services\Detection\StackChecks;
use App\Services\Detection\StackDetector;
use App\Services\Process\SystemdUnitBuilder;
use App\Services\Runtime\JsTooling;
use App\Services\Runtime\RepositoryFacts;
use Tests\Support\RepositoryFixture;

function startScriptFacts(array $scripts): RepositoryFacts
{
    return new RepositoryFacts(['package.json' => (string) json_encode(['scripts' => $scripts, 'dependencies' => ['express' => '^5.1.0']])], ['package-lock.json'], complete: true);
}

test('a start:prod script is what a JavaScript app runs, ahead of start', function () {
    $application = new Application(['type' => ApplicationType::Js, 'js_runtime' => 'node']);

    expect(JsTooling::for($application, RepositoryFixture::facts('express-myapi'))?->startScript)->toBe('start:prod')
        ->and(JsTooling::for($application, RepositoryFixture::facts('express-api'))?->startScript)->toBe('start');
});

test('a start:prod script that puts the app in the background is passed over for start', function (string $daemon) {
    expect(JsTooling::startScriptFor(startScriptFacts(['start' => 'node server.js', 'start:prod' => $daemon])))->toBe('start');
})->with([
    'pm2' => ['pm2 start server.js --name api'],
    'forever' => ['NODE_ENV=production forever start server.js'],
]);

test('a start script that puts the app in the background is not sure, since systemd would restart it in a loop', function () {
    $detected = app(StackDetector::class)->detect(startScriptFacts(['start' => 'pm2 start server.js']), fn(string $runtime): array => []);

    expect($detected->verdict())->toBe('unsure')
        ->and($detected->unsettled['framework'] ?? '')->toContain('pm2 start');
});

test('the web process runs the start:prod script', function (string $runtime, string $command) {
    $application = Application::factory()->create([
        'slug' => 'api',
        'type' => ApplicationType::Js,
        'framework' => '',
        'js_runtime' => $runtime,
        'php_version' => null,
        'bun_version' => '1.4.2',
        'node_version' => '24.21.0',
        'stack' => ['runtimes' => [], 'framework' => null, 'tooling' => (new JsTooling($runtime === 'node' ? 'npm' : 'bun', true, true, true, startScript: 'start:prod'))->toArray()],
    ]);

    expect(app(SystemdUnitBuilder::class)->webCommand($application))->toBe($command);
})->with([
    'node' => ['node', '/opt/asdf-data/installs/nodejs/24.21.0/bin/npm run start:prod'],
    'bun' => ['bun', '/opt/asdf-data/installs/bun/1.4.2/bin/bun run --bun start:prod'],
]);

test('a saved stack can only name a start script Coresmith picks', function () {
    expect(JsTooling::fromArray(['manager' => 'npm', 'start' => true, 'start_script' => 'start:prod'])?->startScript)->toBe('start:prod')
        ->and(JsTooling::fromArray(['manager' => 'npm', 'start' => true, 'start_script' => 'start; reboot'])?->startScript)->toBe('start')
        ->and(JsTooling::fromArray(['manager' => 'npm', 'start' => true])?->startScript)->toBe('start');
});

test('a start script for development is fine when start:prod runs the app', function () {
    $detected = app(StackDetector::class)->detect(startScriptFacts(['start' => 'nodemon src/index.js', 'start:prod' => 'node src/index.js']), fn(string $runtime): array => []);

    expect($detected->verdict())->toBe('ready')
        ->and($detected->sources['framework'])->toContain('start:prod');
});

test('a start:prod script on its own is something to start', function () {
    $facts = startScriptFacts(['start:prod' => 'node src/index.js']);
    $blockers = app(StackChecks::class)->blockers(new Application(['directory' => '/', 'type' => ApplicationType::Js, 'framework' => '', 'js_runtime' => 'node']), $facts, fn(string $runtime): array => []);

    expect($blockers)->toBe([]);
});

test('the lockfile of the manager packageManager names is the one installed from, when it is committed', function () {
    $facts = new RepositoryFacts(['package.json' => (string) json_encode(['packageManager' => 'yarn@1.22.22', 'scripts' => ['build' => 'astro build']])], ['pnpm-lock.yaml', 'yarn.lock'], complete: true);
    $tooling = JsTooling::for(new Application(['type' => ApplicationType::Static]), $facts);

    expect($tooling?->manager)->toBe('yarn')
        ->and($tooling?->managerVersion)->toBe('1.22.22')
        ->and($tooling?->locked)->toBeTrue();
});
