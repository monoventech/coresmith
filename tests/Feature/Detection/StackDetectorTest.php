<?php

declare(strict_types=1);

use App\Services\Detection\DetectedStack;
use App\Services\Detection\StackDetector;
use App\Services\Runtime\RepositoryFacts;
use Tests\Support\RepositoryFixture;

function stackDetectorRun(string|RepositoryFacts $repository): DetectedStack
{
    $facts = is_string($repository) ? RepositoryFixture::facts($repository) : $repository;

    return app(StackDetector::class)->detect($facts, fn(string $runtime): array => []);
}

test('each repository gets the stack its files settle', function (string $fixture, string $verdict, ?string $type, ?string $framework, ?string $runtime, ?string $web) {
    $detected = stackDetectorRun($fixture);

    expect([$detected->verdict(), $detected->type, $detected->framework, $detected->jsRuntime, $detected->webDirectory])
        ->toBe([$verdict, $type, $framework, $runtime, $web]);
})->with([
    'laravel 13' => ['laravel-13', 'ready', 'php', 'laravel', null, '/public'],
    'laravel vue starter kit' => ['laravel-vue-starter', 'ready', 'php', 'laravel', null, '/public'],
    'laravel held to php 8.4' => ['laravel-locked-84', 'ready', 'php', 'laravel', null, '/public'],
    'laravel with a platform PHP its lock has outgrown' => ['laravel-platform-behind-lock', 'blocked', 'php', 'laravel', null, '/public'],
    'symfony demo' => ['symfony-demo', 'ready', 'php', 'symfony', null, '/public'],
    'slim skeleton' => ['slim-skeleton', 'ready', 'php', '', null, '/public'],
    'a composer library' => ['guzzle-library', 'unsure', null, null, null, null],
    'rails 8, fizzy' => ['rails-fizzy', 'ready', 'ruby', 'rails', null, null],
    'rails api only' => ['rails-api', 'ready', 'ruby', 'rails', null, null],
    'rails with jsbundling' => ['rails-jsbundling', 'ready', 'ruby', 'rails', null, null],
    'sinatra on puma' => ['sinatra-puma', 'ready', 'ruby', 'sinatra', null, null],
    'sinatra locked by bundler 2.2' => ['sinatra-old-bundler', 'unsure', 'ruby', 'sinatra', null, null],
    'sinatra with a lock too old to say its bundler' => ['sinatra-ancient-lock', 'unsure', 'ruby', 'sinatra', null, null],
    'sinatra with activerecord' => ['sinatra-activerecord', 'ready', 'ruby', 'sinatra', null, null],
    'sinatra without Gemfile.lock' => ['sinatra-no-lock', 'blocked', 'ruby', 'sinatra', null, null],
    'sinatra on thin' => ['sinatra-thin', 'blocked', 'ruby', 'sinatra', null, null],
    'sveltekit on adapter-node' => ['sveltekit-node', 'ready', 'js', 'sveltekit', 'bun', null],
    'sveltekit on svelte-adapter-bun' => ['sveltekit-bun', 'ready', 'js', 'sveltekit', 'bun', null],
    'sveltekit on adapter-auto' => ['sveltekit-auto', 'blocked', 'js', 'sveltekit', 'node', null],
    'sveltekit on adapter-vercel, realworld' => ['sveltekit-realworld', 'blocked', 'js', 'sveltekit', 'node', null],
    'sveltekit on adapter-static' => ['sveltekit-static', 'ready', 'static', '', null, '/build'],
    'next.js' => ['next-app', 'ready', 'js', 'nextjs', 'node', null],
    'next.js static export' => ['next-export', 'ready', 'static', '', null, '/out'],
    'next.js without a start script' => ['next-no-start', 'blocked', 'js', 'nextjs', 'node', null],
    'nuxt' => ['nuxt-app', 'ready', 'js', 'nuxt', 'node', null],
    'nuxt generate' => ['nuxt-generate', 'ready', 'static', '', null, '/.output/public'],
    'astro on node' => ['astro-node', 'ready', 'js', 'astro', 'node', null],
    'astro static, astro-paper' => ['astro-paper', 'ready', 'static', '', null, '/dist'],
    'vite react' => ['vite-react', 'ready', 'static', '', null, '/dist'],
    'vite with its own outDir' => ['vite-outdir', 'unsure', 'static', '', null, null],
    'express with a start script' => ['express-api', 'ready', 'js', '', 'node', null],
    'a main file and no start script' => ['js-main-only', 'unsure', 'js', null, 'node', null],
    'neither a start script nor a main file' => ['js-no-start', 'unsure', 'js', null, 'node', null],
    'a library with a main file, http-proxy-middleware' => ['js-library-main', 'unsure', 'js', null, 'node', null],
    'a GitHub action, actions-gh-pages' => ['js-github-action', 'unsure', 'js', null, 'node', null],
    'an electron app, Publii' => ['js-electron-start', 'unsure', 'js', null, 'node', null],
    'nuxt only for the docs, bootstrap-vue' => ['js-nuxt-docs-library', 'unsure', 'js', null, 'node', null],
    'nest start, MyAPI' => ['express-myapi', 'ready', 'js', '', 'node', null],
    'a start script but no server package' => ['js-start-no-server', 'unsure', 'js', null, 'node', null],
    'create react app' => ['react-scripts-app', 'ready', 'static', '', null, '/build'],
    'a symfony app without the bundle in its own require, Sylius' => ['symfony-sylius', 'ready', 'php', 'symfony', null, '/public'],
    'a laravel package, aimeos-laravel' => ['laravel-package', 'unsure', null, null, null, null],
    'a symfony bundle, ContentfulBundle' => ['symfony-bundle', 'unsure', null, null, null, null],
    'a ruby gem, sinatra itself' => ['ruby-gem-sinatra', 'unsure', null, null, null, null],
    'deno' => ['deno-app', 'ready', 'js', '', 'deno', null],
    'deno with nothing to start' => ['deno-no-start', 'unsure', 'js', null, 'deno', null],
    'a deno library, fresh-session' => ['deno-library', 'unsure', 'js', null, 'deno', null],
    'plain html' => ['static-html', 'ready', 'static', '', null, '/'],
    'a pnpm workspace root' => ['pnpm-workspace-root', 'unsure', null, null, null, null],
    'an npm workspace root' => ['npm-workspace-root', 'unsure', null, null, null, null],
    'nothing Coresmith can run' => ['empty-repository', 'unsure', null, null, null, null],
]);

test('a PHP app with no composer.lock and no PHP version leaves the version not sure', function (array $files, string $verdict) {
    $facts = new RepositoryFacts(['composer.json' => (string) json_encode(['require' => ['slim/slim' => '^4.12', 'phpoffice/phpspreadsheet' => '^1.29']]), ...$files], markers: ['public/index.php'], complete: true);
    $detected = stackDetectorRun($facts);

    expect($detected->verdict())->toBe($verdict)
        ->and($detected->unsettled['runtime_version'] ?? null)->toBe($verdict === 'unsure' ? 'composer.json names no PHP version and there is no composer.lock, so Coresmith can\'t tell which PHP your packages allow. Add one to require in composer.json, for example "php": "^8.3", or commit composer.lock.' : null);
})->with([
    'nothing names it, frank270/nPhpSlim4Skeleton' => [[], 'unsure'],
    '.tool-versions names it' => [['.tool-versions' => "php 8.4.25\n"], 'ready'],
]);

test('a PHP app whose composer.json names a PHP version is ready without composer.lock', function () {
    $facts = new RepositoryFacts(['composer.json' => (string) json_encode(['require' => ['php' => '^8.2', 'slim/slim' => '^4.12']])], markers: ['public/index.php'], complete: true);

    expect(stackDetectorRun($facts)->verdict())->toBe('ready');
});

test('a Nuxt app that may build for another host is not sure', function (string $config, array $dependencies, string $reason) {
    $facts = new RepositoryFacts([
        'package.json' => (string) json_encode(['scripts' => ['build' => 'nuxt build'], 'dependencies' => ['nuxt' => '^3.16.0', ...$dependencies]]),
        'nuxt.config.ts' => $config,
    ], ['package-lock.json'], complete: true);
    $detected = stackDetectorRun($facts);

    expect($detected->verdict())->toBe('unsure')
        ->and($detected->unsettled['framework'] ?? '')->toContain($reason);
})->with([
    'a preset chosen by an expression, vishnujayadevan.com' => ["export default defineNuxtConfig({\n  nitro: {\n    preset: process.env.NODE_ENV === \"production\" ? \"github\" : undefined,\n  },\n})\n", [], 'github'],
    'NuxtHub, BilleterieJO' => ["export default defineNuxtConfig({ modules: ['@nuxthub/core'] })\n", ['@nuxthub/core' => '^0.8.0'], '@nuxthub/core'],
]);

function stackDetectorOldRails(array $extra = []): RepositoryFacts
{
    $facts = RepositoryFixture::facts('rails-api');
    $lock = (string) preg_replace(['/^    (rails|railties|activerecord|activesupport|actionpack) \([0-9.]+\)$/m', '/^    (bigdecimal|mutex_m) \([^)]*\)\n/m'], ['    $1 (7.0.8.7)', ''], (string) $facts->file('Gemfile.lock'));
    $files = ['Gemfile.lock' => $lock, ...array_diff_key($facts->files, ['Gemfile.lock' => true, '.ruby-version' => true, '.tool-versions' => true]), ...$extra];

    return new RepositoryFacts($files, $facts->lockfiles, $facts->locked, $facts->markers, $facts->envKeys, $facts->complete);
}

test('rails with no Ruby version named is not sure, since gems locked for an older Ruby may not install on the newest', function (array $extra, string $rails) {
    $detected = stackDetectorRun(stackDetectorOldRails($extra));

    expect($detected->verdict())->toBe('unsure')
        ->and($detected->unsettled['runtime_version'] ?? '')->toContain("Rails {$rails}")
        ->and($detected->unsettled['runtime_version'] ?? '')->toContain('.ruby-version');
})->with([
    'rails 7.0, which loads gems Ruby 3.4 dropped' => [[], '7.0.8.7'],
    'rails 7.1 with a native gem built for older Rubies, jedaeroweb/homepage' => [['Gemfile.lock' => "GEM\n  remote: https://rubygems.org/\n  specs:\n    bigdecimal (4.0.1)\n    mutex_m (0.3.0)\n    puma (6.6.0)\n    railties (7.1.6)\n    sqlite3 (1.7.3-aarch64-linux)\n\nPLATFORMS\n  aarch64-linux\n\nDEPENDENCIES\n  puma\n  rails\n\nBUNDLED WITH\n   2.7.0\n"], '7.1.6'],
]);

test('rails before 7.1 with its Ruby version named is ready', function () {
    expect(stackDetectorRun(stackDetectorOldRails(['.ruby-version' => "3.3.6\n"]))->verdict())->toBe('ready');
});

test('a Nuxt 2 app that names no Node.js version is not sure, since webpack 4 fails on the Node.js Coresmith would pick', function () {
    $facts = new RepositoryFacts(['package.json' => (string) json_encode(['scripts' => ['build' => 'nuxt build', 'start' => 'nuxt start'], 'dependencies' => ['nuxt' => '^2.14.0']])], ['yarn.lock'], complete: true);
    $detected = stackDetectorRun($facts);

    expect($detected->verdict())->toBe('unsure')
        ->and($detected->unsettled['runtime_version'] ?? '')->toContain('Nuxt ^2.14.0 builds with webpack 4');
});

test('the server language wins over a frontend package.json', function () {
    expect(stackDetectorRun('laravel-13')->type)->toBe('php')
        ->and(stackDetectorRun('rails-jsbundling')->type)->toBe('ruby');
});

test('a ready stack says where each value came from', function () {
    $detected = stackDetectorRun('laravel-13');

    expect($detected->sources['type'])->toContain('composer.json')
        ->and($detected->sources['framework'])->toContain('laravel/framework')
        ->and($detected->sources['web_directory'])->toContain('public/index.php')
        ->and($detected->sources['runtime_version'])->toStartWith('PHP ')
        ->and($detected->sources['runtime_version'])->toContain('composer.json');
});

test('PHP stops where composer.lock stops', function () {
    $detected = stackDetectorRun('laravel-locked-84');

    expect($detected->stack?->versions()['php'] ?? '')->toStartWith('8.4.')
        ->and($detected->sources['runtime_version'])->toContain('composer.lock');
});

test('a blocked stack says why and how to fix it', function (string $fixture, string $field, string $reason, string $fix) {
    $blocker = stackDetectorRun($fixture)->blockers[0];

    expect($blocker->field)->toBe($field)
        ->and($blocker->reason)->toContain($reason)
        ->and($blocker->fix)->toContain($fix);
})->with([
    'no Gemfile.lock' => ['sinatra-no-lock', 'repository', 'Gemfile.lock', 'Commit Gemfile.lock'],
    'no puma' => ['sinatra-thin', 'repository', 'Puma', 'gem "puma"'],
    'a platform adapter' => ['sveltekit-realworld', 'framework', '@sveltejs/adapter-vercel', '@sveltejs/adapter-node'],
    'adapter-auto' => ['sveltekit-auto', 'framework', '@sveltejs/adapter-auto', '@sveltejs/adapter-node'],
    'next.js without start' => ['next-no-start', 'repository', 'start script', '"start": "next start"'],
    'a platform PHP behind the lock' => ['laravel-platform-behind-lock', 'repository', 'config.platform.php', 'composer update --lock'],
]);

test('a field the files do not settle is left with the reason', function (string $fixture, string $field, string $reason) {
    expect(stackDetectorRun($fixture)->unsettled[$field] ?? '')->toContain($reason);
})->with([
    'a library' => ['guzzle-library', 'type', 'library'],
    'a pnpm workspace' => ['pnpm-workspace-root', 'type', 'workspace'],
    'an npm workspace' => ['npm-workspace-root', 'type', 'workspace'],
    'a custom outDir' => ['vite-outdir', 'web_directory', 'outDir'],
    'no stack at all' => ['empty-repository', 'type', 'composer.json'],
    'no start script' => ['js-no-start', 'framework', 'no start script'],
    'only a main file' => ['js-library-main', 'framework', 'looks like a library'],
    'a desktop app' => ['js-electron-start', 'framework', 'electron'],
    'a framework the build does not build' => ['js-nuxt-docs-library', 'framework', 'nuxt build'],
    'a ruby gem' => ['ruby-gem-sinatra', 'type', 'Ruby gem'],
    'a laravel package' => ['laravel-package', 'type', 'library'],
    'a lock from an old bundler' => ['sinatra-old-bundler', 'runtime_version', 'Bundler 2.2.22'],
    'a lock too old to say its bundler' => ['sinatra-ancient-lock', 'runtime_version', "doesn't say which Bundler"],
    'a symfony bundle' => ['symfony-bundle', 'type', 'library'],
    'deno with no start task' => ['deno-no-start', 'framework', 'no start task'],
    'a deno library' => ['deno-library', 'framework', 'library'],
]);

test('a composer.lock too big to read leaves the PHP version unsure', function () {
    $facts = RepositoryFixture::facts('laravel-13');
    $cut = new RepositoryFacts([...$facts->files, 'composer.lock' => '{"packages": [{"name": "laravel/fr'], $facts->lockfiles, $facts->locked, $facts->markers, $facts->envKeys, true);
    $detected = stackDetectorRun($cut);

    expect($detected->verdict())->toBe('unsure')
        ->and($detected->unsettled['runtime_version'] ?? '')->toContain('composer.lock');
});

test('a probe that did not finish settles nothing', function () {
    $detected = stackDetectorRun(new RepositoryFacts(['composer.json' => '{"require":{"laravel/framework":"^13.0"}}']));

    expect($detected->verdict())->toBe('unsure')
        ->and($detected->type)->toBeNull()
        ->and($detected->unsettled)->toHaveKey('type');
});
