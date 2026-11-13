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
    'symfony demo' => ['symfony-demo', 'ready', 'php', 'symfony', null, '/public'],
    'slim skeleton' => ['slim-skeleton', 'ready', 'php', '', null, '/public'],
    'a composer library' => ['guzzle-library', 'unsure', null, null, null, null],
    'rails 8, fizzy' => ['rails-fizzy', 'ready', 'ruby', 'rails', null, null],
    'rails api only' => ['rails-api', 'ready', 'ruby', 'rails', null, null],
    'rails with jsbundling' => ['rails-jsbundling', 'ready', 'ruby', 'rails', null, null],
    'sinatra on puma' => ['sinatra-puma', 'ready', 'ruby', 'sinatra', null, null],
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
    'a ruby gem, sinatra itself' => ['ruby-gem-sinatra', 'unsure', null, null, null, null],
    'deno' => ['deno-app', 'ready', 'js', '', 'deno', null],
    'deno with nothing to start' => ['deno-no-start', 'unsure', 'js', null, 'deno', null],
    'a deno library, fresh-session' => ['deno-library', 'unsure', 'js', null, 'deno', null],
    'plain html' => ['static-html', 'ready', 'static', '', null, '/'],
    'a pnpm workspace root' => ['pnpm-workspace-root', 'unsure', null, null, null, null],
    'an npm workspace root' => ['npm-workspace-root', 'unsure', null, null, null, null],
    'nothing Coresmith can run' => ['empty-repository', 'unsure', null, null, null, null],
]);

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
