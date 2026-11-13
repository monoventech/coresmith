<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Models\Application;
use App\Services\Runtime\Limit;
use App\Services\Runtime\RepositoryFacts;
use App\Services\Runtime\RuntimeDetector;

function detectorApp(array $attributes = []): Application
{
    return new Application(['type' => ApplicationType::Php, 'framework' => 'laravel', ...$attributes]);
}

function detectorFacts(array $files = [], array $lockfiles = []): RepositoryFacts
{
    return new RepositoryFacts($files, $lockfiles);
}

test('a rails app whose css builds through cssbundling gets node and its package manager', function () {
    $detection = app(RuntimeDetector::class)->detect(detectorApp(['type' => ApplicationType::Ruby, 'framework' => 'rails']), detectorFacts([
        'package.json' => '{"scripts":{"build:css":"sass ./app/assets/stylesheets/application.sass.scss:./app/assets/builds/application.css"}}',
    ], ['package-lock.json']));

    expect($detection->runtimes)->toBe(['ruby', 'nodejs'])
        ->and($detection->tooling?->manager)->toBe('npm')
        ->and($detection->tooling?->build)->toBeFalse();
});

test('a laravel app that builds with bun needs php and bun', function () {
    $detection = app(RuntimeDetector::class)->detect(detectorApp(), detectorFacts([
        'composer.json' => '{"require":{"php":"^8.4"}}',
        'package.json' => '{"scripts":{"build":"vite build"}}',
    ], ['bun.lock']));

    expect($detection->runtimes)->toBe(['php', 'bun'])
        ->and($detection->requirements['php']->constraint)->toBe('^8.4')
        ->and($detection->requirements['php']->field)->toBe('require.php in composer.json')
        ->and($detection->requirements)->not->toHaveKey('bun')
        ->and($detection->tooling?->manager)->toBe('bun')
        ->and($detection->tooling?->locked)->toBeTrue();
});

test('a php app with nothing to build needs only php', function (array $files) {
    $detection = app(RuntimeDetector::class)->detect(detectorApp(), detectorFacts($files));

    expect($detection->runtimes)->toBe(['php'])->and($detection->tooling)->toBeNull();
})->with([
    'no package.json' => [[]],
    'package.json without a build script' => [['package.json' => '{"scripts":{"dev":"vite"}}']],
]);

test('.tool-versions wins over the runtime\'s own files', function () {
    $detection = app(RuntimeDetector::class)->detect(detectorApp(), detectorFacts([
        '.tool-versions' => "# pinned\r\nphp 8.3.12\r\nnode 22.16.0\r\n",
        'composer.json' => '{"require":{"php":"^8.4"}}',
    ]));

    expect($detection->requirements['php']->constraint)->toBe('8.3.12')
        ->and($detection->requirements['php']->source)->toBe('.tool-versions');
});

test('a .tool-versions entry that is not a version is set aside with a note', function () {
    $detection = app(RuntimeDetector::class)->detect(detectorApp(), detectorFacts([
        '.tool-versions' => "php ref:php-8.4.0\n",
        'composer.json' => '{"require":{"php":"^8.4"}}',
    ]));

    expect($detection->requirements['php']->source)->toBe('composer.json')
        ->and($detection->notes)->toBe(['Ignored php ref:php-8.4.0 in .tool-versions, because it is not a version number.']);
});

test('node versions are read the way nvm reads them', function (string $nvmrc, ?string $constraint) {
    $application = detectorApp(['type' => ApplicationType::Js, 'framework' => 'sveltekit', 'js_runtime' => 'node']);
    $detection = app(RuntimeDetector::class)->detect($application, detectorFacts(['.nvmrc' => $nvmrc]));

    expect(($detection->requirements['nodejs'] ?? null)?->constraint)->toBe($constraint);
})->with([
    'a major' => ["22\n", '22'],
    'with a v' => ["v22.16.0\n", '22.16.0'],
    'the current lts' => ["lts/*\n", '24'],
    'an lts codename' => ["lts/iron\n", '20'],
    'the newest' => ["node\n", '26'],
    'an unknown codename' => ["lts/zeppelin\n", null],
]);

test('the bun source note names every place its version can be declared', function () {
    expect(RuntimeDetector::SOURCES['bun'])->toBe('.tool-versions, packageManager or engines.bun in package.json');
});

test('.tool-versions accepts both the node and nodejs asdf tokens', function (string $token) {
    $application = detectorApp(['type' => ApplicationType::Js, 'framework' => 'sveltekit', 'js_runtime' => 'node']);
    $detection = app(RuntimeDetector::class)->detect($application, detectorFacts(['.tool-versions' => "{$token} 22.16.0\n"]));

    expect($detection->requirements['nodejs']->constraint)->toBe('22.16.0')
        ->and($detection->requirements['nodejs']->source)->toBe('.tool-versions');
})->with(['node', 'nodejs']);

test('bun pinned through packageManager is an exact version', function () {
    $application = detectorApp(['type' => ApplicationType::Js, 'framework' => 'sveltekit', 'js_runtime' => 'bun']);
    $detection = app(RuntimeDetector::class)->detect($application, detectorFacts(['package.json' => '{"packageManager":"bun@1.3.9","scripts":{"build":"vite build"}}'], ['bun.lock']));

    expect($detection->runtimes)->toBe(['bun'])
        ->and($detection->requirements['bun']->constraint)->toBe('1.3.9')
        ->and($detection->requirements['bun']->raw)->toBe('bun@1.3.9');
});

test('a lockfile from another package manager brings its runtime along', function (string $jsRuntime, string $lockfile, array $runtimes, string $manager) {
    $application = detectorApp(['type' => ApplicationType::Js, 'framework' => 'sveltekit', 'js_runtime' => $jsRuntime]);
    $detection = app(RuntimeDetector::class)->detect($application, detectorFacts(['package.json' => '{"scripts":{"build":"vite build"}}'], [$lockfile]));

    expect($detection->runtimes)->toBe($runtimes)->and($detection->tooling?->manager)->toBe($manager);
})->with([
    'a bun app with an npm lockfile' => ['bun', 'package-lock.json', ['bun', 'nodejs'], 'npm'],
    'a node app with a bun lockfile' => ['node', 'bun.lock', ['nodejs', 'bun'], 'bun'],
    'a node app with a pnpm lockfile' => ['node', 'pnpm-lock.yaml', ['nodejs'], 'pnpm'],
]);

test('a js app without a lockfile uses its own runtime\'s package manager', function (string $jsRuntime, string $manager) {
    $application = detectorApp(['type' => ApplicationType::Js, 'framework' => '', 'js_runtime' => $jsRuntime]);
    $detection = app(RuntimeDetector::class)->detect($application, detectorFacts(['package.json' => '{"scripts":{"start":"node server.js"}}']));

    expect($detection->tooling?->manager)->toBe($manager)->and($detection->tooling?->locked)->toBeFalse();
})->with([['node', 'npm'], ['bun', 'bun']]);

test('a deno app needs only deno and reads .dvmrc', function () {
    $application = detectorApp(['type' => ApplicationType::Js, 'framework' => '', 'js_runtime' => 'deno']);
    $detection = app(RuntimeDetector::class)->detect($application, detectorFacts(['.dvmrc' => "2.8.3\n", 'deno.json' => '{"tasks":{"start":"deno run -A main.ts"}}']));

    expect($detection->runtimes)->toBe(['deno'])
        ->and($detection->requirements['deno']->constraint)->toBe('2.8.3')
        ->and($detection->tooling?->manager)->toBe('deno')
        ->and($detection->tooling?->start)->toBeTrue()
        ->and($detection->tooling?->build)->toBeFalse();
});

test('ruby versions are read from .ruby-version, then the Gemfile', function (array $files, string $constraint) {
    $application = detectorApp(['type' => ApplicationType::Ruby, 'framework' => 'rails']);
    $requirement = app(RuntimeDetector::class)->detect($application, detectorFacts($files))->requirements['ruby'];

    expect($requirement->constraint)->toBe($constraint)->and($requirement->flavour)->toBe('gem');
})->with([
    'a ruby- prefix' => [['.ruby-version' => "ruby-3.3.6\n"], '3.3.6'],
    'the gemfile' => [['Gemfile' => "source \"https://rubygems.org\"\nruby \"~> 3.4\"\n"], '~> 3.4'],
]);

test('a rails app with a package.json gets node and its package manager, whatever its scripts', function () {
    $detection = app(RuntimeDetector::class)->detect(detectorApp(['type' => ApplicationType::Ruby, 'framework' => 'rails']), detectorFacts(['package.json' => '{"devDependencies":{"vite":"^6.0.0","vite-plugin-ruby":"^5.1.0"}}'], ['package-lock.json']));

    expect($detection->runtimes)->toBe(['ruby', 'nodejs'])
        ->and($detection->tooling?->manager)->toBe('npm');
});

test('a rails app that builds javascript gets node for its yarn lockfile', function () {
    $application = detectorApp(['type' => ApplicationType::Ruby, 'framework' => 'rails']);
    $detection = app(RuntimeDetector::class)->detect($application, detectorFacts(['package.json' => '{"scripts":{"build":"esbuild app/javascript/*.*"}}'], ['yarn.lock']));

    expect($detection->runtimes)->toBe(['ruby', 'nodejs'])->and($detection->tooling?->manager)->toBe('yarn');
});

test('a static site needs a JavaScript runtime only when it has a build script, and its lockfile picks which', function (array $files, array $lockfiles, array $runtimes) {
    $application = detectorApp(['type' => ApplicationType::Static, 'framework' => '']);

    expect(app(RuntimeDetector::class)->detect($application, detectorFacts($files, $lockfiles))->runtimes)->toBe($runtimes);
})->with([
    'plain html' => [[], [], []],
    'an astro build with no lockfile' => [['package.json' => '{"scripts":{"build":"astro build"}}'], [], ['nodejs']],
    'an astro build with bun.lock' => [['package.json' => '{"scripts":{"build":"astro build"}}'], ['bun.lock'], ['bun']],
]);

function detectorLockedNette(): string
{
    return (string) json_encode([
        'packages' => [
            ['name' => 'league/config', 'version' => 'v1.2.0', 'require' => ['php' => '^7.4 || ^8.0', 'nette/schema' => '^1.2']],
            ['name' => 'nette/schema', 'version' => 'v1.3.2', 'require' => ['php' => '8.1 - 8.4']],
            ['name' => 'nette/utils', 'version' => 'v4.0.5', 'require' => ['php' => '8.0 - 8.4']],
            ['name' => 'psr/log', 'version' => '3.0.2', 'require' => []],
        ],
        'packages-dev' => [
            ['name' => 'phpunit/phpunit', 'version' => '9.6.0', 'require' => ['php' => '>=7.3 <8.4']],
        ],
    ]);
}

test('composer.lock limits php by what each package a deploy installs allows', function () {
    $detection = app(RuntimeDetector::class)->detect(detectorApp(), detectorFacts([
        'composer.json' => '{"require":{"php":"^8.2"}}',
        'composer.lock' => detectorLockedNette(),
    ]));

    expect(array_map(fn(Limit $limit): array => [$limit->package, $limit->version, $limit->constraint], $detection->limits['php'] ?? []))->toBe([
        ['league/config', '1.2.0', '^7.4 || ^8.0'],
        ['nette/schema', '1.3.2', '8.1 - 8.4'],
        ['nette/utils', '4.0.5', '8.0 - 8.4'],
    ]);
});

test('a composer.lock that cannot be read sets no limits and says why', function () {
    $detection = app(RuntimeDetector::class)->detect(detectorApp(), detectorFacts([
        'composer.json' => '{"require":{"php":"^8.2"}}',
        'composer.lock' => '{"packages": [',
    ]));

    expect($detection->limits)->toBe([])
        ->and($detection->notes)->toContain('composer.lock could not be read, so PHP was chosen from composer.json alone.');
});
