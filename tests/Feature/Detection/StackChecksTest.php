<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Models\Application;
use App\Services\Detection\Blocker;
use App\Services\Detection\StackChecks;
use App\Services\Runtime\RepositoryFacts;
use Tests\Support\RepositoryFixture;

/**
 * @param  array<string, mixed>  $choice
 * @return list<Blocker>
 */
function stackChecksFor(string|RepositoryFacts $repository, array $choice): array
{
    $facts = is_string($repository) ? RepositoryFixture::facts($repository) : $repository;

    return app(StackChecks::class)->blockers(new Application(['directory' => '/', 'web_directory' => '/public', ...$choice]), $facts, fn(string $runtime): array => []);
}

test('Laravel picked for a repository without artisan is refused', function () {
    $blockers = stackChecksFor('slim-skeleton', ['type' => ApplicationType::Php, 'framework' => 'laravel']);

    expect($blockers)->toHaveCount(1)
        ->and($blockers[0]->field)->toBe('framework')
        ->and($blockers[0]->reason)->toContain('artisan');
});

test('Node picked for a Deno repository is refused, and Deno is suggested', function () {
    $blockers = stackChecksFor('deno-app', ['type' => ApplicationType::Js, 'framework' => '', 'js_runtime' => 'node']);

    expect($blockers[0]->field)->toBe('js_runtime')
        ->and($blockers[0]->reason)->toContain('package.json')
        ->and($blockers[0]->fix)->toContain('Deno');
});

test('a PHP version composer.lock rules out is refused with the reason', function () {
    $blockers = stackChecksFor('laravel-locked-84', ['type' => ApplicationType::Php, 'framework' => 'laravel', 'runtime_pins' => ['php' => '8.5']]);

    expect($blockers)->toHaveCount(1)
        ->and($blockers[0]->field)->toBe('runtime_version')
        ->and($blockers[0]->reason)->toContain('composer.lock holds');
});

test('a platform PHP in composer.json that composer.lock needs more than is refused', function () {
    $blockers = stackChecksFor('laravel-platform-behind-lock', ['type' => ApplicationType::Php, 'framework' => 'laravel']);

    expect($blockers)->toHaveCount(1)
        ->and($blockers[0]->field)->toBe('repository')
        ->and($blockers[0]->reason)->toBe('composer.json sets config.platform.php to 8.2.0, but composer.lock holds spatie/laravel-activitylog 5.0.0, which needs PHP ^8.4, so composer install fails on any server.')
        ->and($blockers[0]->fix)->toContain('config.platform.php');
});

test('a platform PHP in composer.json that composer.lock accepts is fine', function (mixed $platform) {
    $facts = RepositoryFixture::facts('laravel-platform-behind-lock');
    $manifest = $facts->json('composer.json');
    $manifest['config']['platform']['php'] = $platform;
    $accepted = new RepositoryFacts(['composer.json' => (string) json_encode($manifest), ...array_diff_key($facts->files, ['composer.json' => true])], $facts->lockfiles, $facts->locked, $facts->markers, $facts->envKeys, $facts->complete);

    expect(stackChecksFor($accepted, ['type' => ApplicationType::Php, 'framework' => 'laravel']))->toBe([]);
})->with([
    'a version the lock allows' => ['8.4.1'],
    'a short version the lock allows' => ['8.5'],
    'no override' => [false],
    'something composer would not parse' => ['latest'],
]);

test('an app with a custom blueprint gets no blockers, since the blueprint does the build', function () {
    $facts = new RepositoryFacts(['README.md' => 'hugo'], complete: true);

    expect(stackChecksFor($facts, ['type' => ApplicationType::Static, 'framework' => '', 'web_directory' => '/public']))->not->toBe([])
        ->and(stackChecksFor($facts, ['type' => ApplicationType::Static, 'framework' => '', 'web_directory' => '/public', 'custom_blueprint' => "hugo -d public\n"]))->toBe([]);
});

function stackChecksExtensions(array $require, array $packages = []): array
{
    $facts = RepositoryFixture::facts('slim-skeleton');
    $manifest = $facts->json('composer.json');
    $manifest['require'] = [...$manifest['require'], ...$require];
    $lock = (string) json_encode(['packages' => $packages]);
    $needs = new RepositoryFacts(['composer.json' => (string) json_encode($manifest), 'composer.lock' => $lock, ...array_diff_key($facts->files, ['composer.json' => true, 'composer.lock' => true])], $facts->lockfiles, $facts->locked, $facts->markers, $facts->envKeys, $facts->complete);

    return app(StackChecks::class)->blockers(
        new Application(['directory' => '/', 'web_directory' => '/public', 'type' => ApplicationType::Php, 'framework' => '']),
        $needs,
        fn(string $runtime): array => $runtime === 'php' ? ['8.5.10'] : [],
        extensions: fn(string $version): ?array => $version === '8.5.10' ? ['ctype', 'json', 'mbstring', 'pdo_mysql', 'zend-opcache'] : null,
    );
}

test('a PHP extension composer needs that the chosen PHP lacks is refused', function () {
    $blockers = stackChecksExtensions(['ext-amqp' => '*'], [['name' => 'symfony/amqp-messenger', 'version' => 'v7.2.3', 'require' => ['ext-amqp' => '*']]]);

    expect($blockers)->toHaveCount(1)
        ->and($blockers[0]->field)->toBe('repository')
        ->and($blockers[0]->reason)->toContain('amqp PHP extension, which PHP 8.5.10')
        ->and($blockers[0]->fix)->toContain('ext-amqp');
});

test('a PHP extension in a locked package that the chosen PHP lacks is refused', function () {
    $blockers = stackChecksExtensions([], [['name' => 'mongodb/mongodb', 'version' => '1.20.0', 'require' => ['ext-mongodb' => '^1.21']]]);

    expect($blockers)->toHaveCount(1)
        ->and($blockers[0]->reason)->toContain('mongodb/mongodb 1.20.0 in composer.lock needs the mongodb PHP extension');
});

test('PHP extensions the chosen PHP has, a polyfill provides, or nobody can be sure of are fine', function (array $require, array $packages, bool $known) {
    expect($known ? stackChecksExtensions($require, $packages) : stackChecksFor(RepositoryFixture::facts('slim-skeleton'), ['type' => ApplicationType::Php, 'framework' => '']))->toBe([]);
})->with([
    'extensions it has, by any spelling' => [['ext-PDO_MySQL' => '*', 'ext-zend-opcache' => '*', 'ext-json' => '*'], [], true],
    'a polyfill provides it' => [['ext-intl' => '*'], [['name' => 'symfony/polyfill-intl-icu', 'version' => 'v1.31.0', 'provide' => ['ext-intl' => '*']]], true],
    'only require-dev needs it' => [[], [], true],
    'no extension list for that PHP' => [['ext-amqp' => '*'], [], false],
]);

test('a start script that fixes its own port is refused, since visitors would get a 502', function (string $framework, string $start, string $port) {
    $facts = new RepositoryFacts(['package.json' => (string) json_encode(['scripts' => ['build' => 'next build', 'start' => $start], 'dependencies' => ['next' => '^16.1.6', 'express' => '^5.1.0']])], ['package-lock.json'], complete: true);
    $blockers = stackChecksFor($facts, ['type' => ApplicationType::Js, 'framework' => $framework, 'js_runtime' => 'node']);

    expect($blockers)->toHaveCount(1)
        ->and($blockers[0]->field)->toBe('repository')
        ->and($blockers[0]->reason)->toBe("The start script sets port {$port}, but Coresmith gives each app its own port in PORT, so visitors would get a 502.")
        ->and($blockers[0]->fix)->toContain('PORT');
})->with([
    'next start -p, uyoopack-tutorial' => ['nextjs', 'next start -p 3100', '3100'],
    '--port=' => ['', 'node server.js --port=4000', '4000'],
    'a PORT= prefix' => ['', 'PORT=5000 node server.js', '5000'],
    'cross-env' => ['', 'cross-env NODE_ENV=production PORT=8080 node dist/main.js', '8080'],
]);

test('a start script that reads PORT is fine', function (string $start) {
    $facts = new RepositoryFacts(['package.json' => (string) json_encode(['scripts' => ['start' => $start], 'dependencies' => ['express' => '^5.1.0']])], ['package-lock.json'], complete: true);

    expect(stackChecksFor($facts, ['type' => ApplicationType::Js, 'framework' => '', 'js_runtime' => 'node']))->toBe([]);
})->with([
    'no port' => ['node server.js'],
    'the PORT variable' => ['next start -p $PORT'],
    'a fallback' => ['node server.js --port=${PORT:-3000}'],
    'a project flag that is not a port' => ['tsc -p tsconfig.json && node dist/index.js'],
    'a variable that only ends in PORT' => ['APP_PORT=3000 node server.js'],
]);

test('a web root without index.php is refused and points to the folder that has it', function () {
    $blockers = stackChecksFor('slim-skeleton', ['type' => ApplicationType::Php, 'framework' => '', 'web_directory' => '/']);

    expect($blockers[0]->field)->toBe('web_directory')
        ->and($blockers[0]->fix)->toContain('/public');
});

test('a static build is served from the folder it writes to', function () {
    $blockers = stackChecksFor('vite-react', ['type' => ApplicationType::Static, 'framework' => '', 'web_directory' => '/']);

    expect($blockers[0]->field)->toBe('web_directory')
        ->and($blockers[0]->fix)->toContain('/dist');
});

test('a choice that differs from the proposal but can run is not refused', function (string $fixture, array $choice) {
    expect(stackChecksFor($fixture, $choice))->toBe([]);
})->with([
    'laravel as plain php' => ['laravel-13', ['type' => ApplicationType::Php, 'framework' => '']],
    'next.js on bun' => ['next-app', ['type' => ApplicationType::Js, 'framework' => 'nextjs', 'js_runtime' => 'bun']],
    'vite served as an app with a start script' => ['express-api', ['type' => ApplicationType::Js, 'framework' => '', 'js_runtime' => 'bun']],
]);

test('a probe that did not finish refuses nothing', function () {
    expect(stackChecksFor(new RepositoryFacts(['package.json' => '{}']), ['type' => ApplicationType::Ruby, 'framework' => 'rails']))->toBe([]);
});
