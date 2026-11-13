<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Detection;

use App\Enums\ApplicationType;
use App\Exceptions\RuntimeUnavailable;
use App\Models\Application;
use App\Services\Runtime\Catalog;
use App\Services\Runtime\JsTooling;
use App\Services\Runtime\RepositoryFacts;
use App\Services\Runtime\RepositoryProbe;
use App\Services\Runtime\ResolvedRuntime;
use App\Services\Runtime\Stack;
use App\Services\Runtime\StackResolver;
use Closure;

final class StackDetector
{
    public const UNFINISHED = "Coresmith couldn't read all of the repository's files, so it can't tell what the app runs on.";

    public const NOTHING = "There is no composer.json, Gemfile, package.json, deno.json or index.html at the repository's root, so Coresmith can't tell what the app runs on.";

    public const WORKSPACE = 'This is a workspace root: it lists other packages, and Coresmith deploys one app per folder. Pick the stack of the app you want.';

    public const LIBRARY = 'composer.json names no framework and there is no index.php at /, /public or /web, so this looks like a library, not an app.';

    public const LOCK_UNREADABLE = "composer.lock couldn't be read whole, so Coresmith can't tell which PHP versions its packages allow. Pick the PHP version yourself.";

    public const PHP_UNNAMED = 'composer.json names no PHP version and there is no composer.lock, so Coresmith can\'t tell which PHP your packages allow. Add one to require in composer.json, for example "php": "^8.3", or commit composer.lock.';

    public const GEM = 'Gemfile loads a gemspec, so this looks like a Ruby gem, not an app.';

    public const MAIN_ONLY = 'package.json names a main file but no start script, so it looks like a library, not a server. Add a start script if it is an app.';

    public const NO_START = "There is no start script in package.json, so Coresmith can't tell how the app starts. Add one, for example \"start\": \"node server.js\".";

    public const NO_SERVER = "The start script doesn't use a server package Coresmith knows, such as Express, Fastify, Hono, Koa or NestJS, so it can't tell the app listens on a port. Pick API or other if it does.";

    public const DENO_LIBRARY = 'deno.json publishes exports and has no start task, so it looks like a library, not an app.';

    public const DENO_NO_START = "There is no start task in deno.json and no main.ts, so Coresmith can't tell how the app starts. Add a start task, for example \"start\": \"deno run --allow-net main.ts\".";

    private const SERVER_PACKAGES = [
        'express', 'fastify', 'hono', '@hono/node-server', 'koa', '@nestjs/core', '@hapi/hapi', 'restify', 'polka', '@adonisjs/core', 'h3', 'elysia',
        'socket.io', 'ws', '@feathersjs/feathers', '@loopback/core', 'sails', '@remix-run/serve', '@react-router/serve', 'json-server', '@strapi/strapi',
        '@keystone-6/core', 'payload', '@medusajs/medusa', 'micro', '@tinyhttp/app', 'hyper-express', 'srvx',
    ];

    private const DEVELOPMENT_TOOLS = [
        'electron', 'react-scripts start', 'vite', 'vite dev', 'vite preview', 'webpack serve', 'webpack-dev-server', 'parcel', 'gatsby develop', 'nodemon',
        'ng serve', 'vue-cli-service serve', 'hexo server', 'next dev', 'nuxt dev', 'nuxi dev', 'astro dev', 'tsx watch', 'ts-node-dev', 'expo start', 'browser-sync', 'live-server',
    ];

    private const COMPILES_AT_START = ['ts-node', 'nest start', 'babel-node'];

    private const SYMFONY_FAMILY = ['symfony/', 'oro/', 'sylius/', 'shopware/', 'pimcore/'];

    private const JS_LOCKFILES = ['bun.lock' => 'bun', 'bun.lockb' => 'bun', 'package-lock.json' => 'node', 'pnpm-lock.yaml' => 'node', 'yarn.lock' => 'node'];

    public function __construct(
        private readonly StackChecks $checks,
        private readonly StackResolver $resolver,
        private readonly Catalog $catalog,
    ) {}

    /**
     * @param  Closure(string): list<string>  $installed
     */
    public function detect(RepositoryFacts $facts, Closure $installed, ?Closure $extensions = null, ?string $arch = null): DetectedStack
    {
        if (!$facts->complete) {
            return new DetectedStack(null, null, null, null, unsettled: ['type' => self::UNFINISHED]);
        }

        $proposal = $this->php($facts) ?? $this->ruby($facts) ?? $this->javascript($facts) ?? $this->html($facts)
            ?? ['type' => null, 'framework' => null, 'js_runtime' => null, 'web' => null, 'sources' => [], 'unsettled' => ['type' => self::NOTHING]];

        if ($proposal['type'] === null) {
            return new DetectedStack(null, null, null, null, $proposal['sources'], $proposal['unsettled']);
        }

        $type = ApplicationType::from($proposal['type']);
        $application = new Application([
            'type' => $type,
            'framework' => $proposal['framework'] === '' ? null : $proposal['framework'],
            'js_runtime' => $proposal['js_runtime'],
            'web_directory' => $proposal['web'] ?? '/public',
            'directory' => '/',
        ]);

        $stack = $this->stack($application, $facts, $installed);
        $runtime = $this->runtimeOf($type, $proposal['js_runtime'], $stack);
        $sources = $proposal['sources'];
        $unsettled = $proposal['unsettled'];

        if ($runtime !== null && isset($stack?->runtimes[$runtime])) {
            $sources['runtime_version'] = $stack->runtimes[$runtime]->describe($this->catalog->label($runtime));
        }

        if ($type === ApplicationType::Php && $facts->has(RepositoryProbe::COMPOSER_LOCK) && !is_array($facts->json(RepositoryProbe::COMPOSER_LOCK)['packages'] ?? null)) {
            $unsettled['runtime_version'] = self::LOCK_UNREADABLE;
        }

        if ($type === ApplicationType::Php && $facts->has('composer.json') && !$facts->has(RepositoryProbe::COMPOSER_LOCK) && $runtime !== null && ($stack?->runtimes[$runtime]->source ?? null) === ResolvedRuntime::FROM_DEFAULT) {
            $unsettled['runtime_version'] = self::PHP_UNNAMED;
        }

        $ruby = $type === ApplicationType::Ruby && $runtime !== null ? ($stack?->runtimes[$runtime] ?? null) : null;
        $rails = $facts->gem('railties');

        if ($ruby !== null && $ruby->source === ResolvedRuntime::FROM_DEFAULT && $rails !== null) {
            $unsettled['runtime_version'] = "Nothing names a Ruby version, so Coresmith would pick the newest, Ruby {$ruby->version}, which gems locked with Rails {$rails} may not install or load on. Add a .ruby-version with the Ruby the app runs on, for example 3.3.6.";
        }

        $node = $type === ApplicationType::Js && $runtime === 'nodejs' ? ($stack?->runtimes[$runtime] ?? null) : null;
        $nuxt = $proposal['framework'] === 'nuxt' ? $facts->dependency('nuxt') : null;

        if ($node !== null && $node->source === ResolvedRuntime::FROM_DEFAULT && $nuxt !== null && preg_match('/\d+/', $nuxt, $major) === 1 && (int) $major[0] < 3) {
            $unsettled['runtime_version'] = "Nothing names a Node.js version, and Nuxt {$nuxt} builds with webpack 4, which fails on Node.js 17 and newer, while Coresmith would pick Node.js {$node->version}. Add an .nvmrc with the version the app builds on, for example 16.";
        }

        return new DetectedStack(
            $proposal['type'],
            $proposal['framework'],
            $proposal['js_runtime'],
            $proposal['web'],
            $sources,
            $unsettled,
            isset($unsettled['framework']) ? [] : $this->checks->blockers($application, $facts, $installed, extensions: $extensions, arch: $arch),
            $stack,
            $runtime,
        );
    }

    /**
     * @return array{type: ?string, framework: ?string, js_runtime: ?string, web: ?string, sources: array<string, string>, unsettled: array<string, string>}|null
     */
    private function php(RepositoryFacts $facts): ?array
    {
        $index = match (true) {
            $facts->exists('public/index.php') => '/public',
            $facts->exists('web/index.php') => '/web',
            $facts->exists('index.php') => '/',
            default => null,
        };

        if (!$facts->has('composer.json')) {
            return $index === null || $this->otherServer($facts) ? null : $this->proposal('php', '', null, $index, [
                'type' => 'From index.php',
                'web_directory' => $this->indexSource($index),
            ]);
        }

        $require = (array) ($facts->json('composer.json')['require'] ?? []);
        $laravel = array_key_exists('laravel/framework', $require) || $facts->locked('laravel/framework') !== null;

        if ($laravel && $facts->exists('artisan')) {
            return $this->proposal('php', 'laravel', null, '/public', [
                'type' => 'From composer.json',
                'framework' => $this->composerSource($facts, 'laravel/framework', $require['laravel/framework'] ?? null),
                'web_directory' => 'Laravel serves public/index.php',
            ]);
        }

        if ($laravel && $index === null) {
            return $this->unsure('type', self::LIBRARY);
        }

        $symfony = $this->symfony($facts, $require);

        if ($symfony !== null && $index === null && !$facts->exists('bin/console')) {
            return $this->unsure('type', self::LIBRARY);
        }

        if ($symfony !== null) {
            return $this->proposal('php', 'symfony', null, '/public', [
                'type' => 'From composer.json',
                'framework' => $symfony,
                'web_directory' => 'Symfony serves public/index.php',
            ]);
        }

        if ($index !== null) {
            return $this->proposal('php', '', null, $index, ['type' => 'From composer.json', 'framework' => 'No framework in composer.json', 'web_directory' => $this->indexSource($index)]);
        }

        return $this->otherServer($facts) ? null : $this->unsure('type', self::LIBRARY);
    }

    /**
     * @return array{type: ?string, framework: ?string, js_runtime: ?string, web: ?string, sources: array<string, string>, unsettled: array<string, string>}|null
     */
    private function ruby(RepositoryFacts $facts): ?array
    {
        if (!$facts->has('Gemfile')) {
            return null;
        }

        if (preg_match('/^\s*gemspec\b/m', (string) $facts->file('Gemfile')) === 1) {
            return $this->unsure('type', self::GEM);
        }

        $sources = ['type' => $facts->has('Gemfile.lock') ? 'From Gemfile and Gemfile.lock' : 'From Gemfile'];

        foreach (['rails' => 'bin/rails', 'sinatra' => 'config.ru'] as $gem => $marker) {
            $version = $facts->gem($gem);

            if ($version === null && !$this->gemfileNames($facts, $gem)) {
                continue;
            }

            if ($gem === 'rails' && !$facts->exists($marker)) {
                return $this->proposal('ruby', null, null, null, $sources, ['framework' => 'Gemfile names rails, but there is no bin/rails, so this looks like a Rails engine or gem, not an app.']);
            }

            return $this->proposal('ruby', $gem, null, null, [...$sources, 'framework' => $version === null ? "{$gem} in Gemfile" : "{$gem} {$version} in Gemfile.lock"], $this->oldBundler($facts));
        }

        return $this->proposal('ruby', null, null, null, $sources, ['framework' => 'Gemfile names neither rails nor sinatra. Coresmith runs Rails and Sinatra apps.']);
    }

    /**
     * @return array{type: ?string, framework: ?string, js_runtime: ?string, web: ?string, sources: array<string, string>, unsettled: array<string, string>}|null
     */
    private function javascript(RepositoryFacts $facts): ?array
    {
        $deno = $facts->has('deno.json') || $facts->has('deno.jsonc');

        if (!$facts->has('package.json')) {
            return $deno ? $this->deno($facts) : null;
        }

        if ($facts->workspace()) {
            return $this->unsure('type', self::WORKSPACE);
        }

        [$runtime, $runtimeSource] = $this->jsRuntime($facts);
        $sources = ['type' => 'From package.json', 'js_runtime' => $runtimeSource];
        $output = $this->checks->staticOutput($facts);

        $build = (string) ($facts->json('package.json')['scripts']['build'] ?? '');

        if ($facts->dependency('@sveltejs/kit') !== null && !str_contains($build, 'vite build')) {
            return $this->unbuilt($runtime, $sources, '@sveltejs/kit', 'vite build', 'SvelteKit');
        }

        if ($facts->dependency('next') !== null && !str_contains($build, 'next build')) {
            return $this->unbuilt($runtime, $sources, 'next', 'next build', 'Next.js');
        }

        if ($facts->dependency('nuxt') !== null && preg_match('/\bnux[ti] (build|generate)\b/', $build) !== 1) {
            return $this->unbuilt($runtime, $sources, 'nuxt', 'nuxt build', 'Nuxt');
        }

        if ($facts->dependency('astro') !== null && !str_contains($build, 'astro build')) {
            return $this->unbuilt($runtime, $sources, 'astro', 'astro build', 'Astro');
        }

        if ($facts->dependency('@sveltejs/kit') !== null) {
            $adapter = $this->checks->svelteAdapter($facts);

            if ($adapter === '@sveltejs/adapter-static') {
                return $this->staticBuild($output, ['type' => 'From @sveltejs/adapter-static in svelte.config.js'], 'Coresmith couldn\'t tell where adapter-static writes, because svelte.config.js sets its own pages folder. Set the web root to that folder.');
            }

            return $this->proposal('js', 'sveltekit', $runtime, null, [...$sources, 'framework' => $adapter === null ? '@sveltejs/kit in package.json' : "@sveltejs/kit with {$adapter}"], $adapter === null ? ['framework' => "Coresmith couldn't tell which adapter svelte.config.js uses. SvelteKit needs @sveltejs/adapter-node to run on a server."] : []);
        }

        if ($facts->dependency('next') !== null) {
            return $this->checks->nextExport($facts)
                ? $this->staticBuild($output, ['type' => "From output: 'export' in next.config"], 'next.config sets its own distDir, so Coresmith can\'t be sure where the export goes. Set the web root to that folder.')
                : $this->proposal('js', 'nextjs', $runtime, null, [...$sources, 'framework' => 'next in package.json']);
        }

        if ($facts->dependency('nuxt') !== null) {
            $preset = $this->checks->nitroPreset($facts);

            return $this->checks->nuxtGenerate($facts)
                ? $this->staticBuild($output, ['type' => 'From the nuxt generate build script'], '')
                : $this->proposal('js', 'nuxt', $runtime, null, [...$sources, 'framework' => 'nuxt in package.json'], match (true) {
                    $preset !== null && !$preset[1] => ['framework' => "nuxt.config can set Nitro's preset to {$preset[0]}, which makes the app for that host, not the Node server Coresmith runs. Set the preset to node-server."],
                    $facts->dependency('@nuxthub/core') !== null => ['framework' => '@nuxthub/core builds the app for Cloudflare, not the Node server Coresmith runs.'],
                    default => [],
                });
        }

        if ($facts->dependency('astro') !== null) {
            return $this->checks->astroServer($facts)
                ? $this->proposal('js', 'astro', $runtime, null, [...$sources, 'framework' => 'astro with @astrojs/node in package.json'])
                : $this->staticBuild($output, ['type' => 'From astro in package.json, with no server adapter'], 'astro.config sets its own outDir, so Coresmith can\'t be sure where the build goes. Set the web root to that folder.');
        }

        if ($facts->dependency('react-scripts') !== null && str_contains($build, 'react-scripts build')) {
            return $this->staticBuild('/build', ['type' => 'From the react-scripts build script in package.json'], '');
        }

        if ($facts->dependency('vite') !== null && str_contains($build, 'vite build') && $this->viteOnly($facts)) {
            return $this->staticBuild($output, ['type' => 'From the vite build script in package.json'], 'vite.config sets its own outDir, so Coresmith can\'t be sure where the build goes. Set the web root to that folder.');
        }

        return $this->started($facts, $runtime, $sources);
    }

    /**
     * @param  array<string, string>  $sources
     * @return array{type: ?string, framework: ?string, js_runtime: ?string, web: ?string, sources: array<string, string>, unsettled: array<string, string>}
     */
    private function started(RepositoryFacts $facts, string $runtime, array $sources): array
    {
        $name = JsTooling::startScriptFor($facts);
        $start = $name === null ? '' : trim((string) ($facts->json('package.json')['scripts'][$name] ?? ''));

        if ($start === '') {
            return $this->proposal('js', null, $runtime, null, $sources, ['framework' => isset($facts->json('package.json')['main']) ? self::MAIN_ONLY : self::NO_START]);
        }

        foreach (self::DEVELOPMENT_TOOLS as $tool) {
            if (preg_match('/(^|[\s;&|(])' . preg_quote($tool, '/') . '(\s|$)/', $start) === 1) {
                return $this->proposal('js', null, $runtime, null, $sources, ['framework' => "The {$name} script runs {$tool}, which isn't a production server. Add a start script that runs the app, for example \"start\": \"node server.js\"."]);
            }
        }

        foreach (self::COMPILES_AT_START as $tool) {
            if (preg_match('/(^|[\s;&|(])' . preg_quote($tool, '/') . '(\s|$)/', $start) === 1) {
                return $this->proposal('js', null, $runtime, null, $sources, ['framework' => "The {$name} script runs {$tool}, which compiles TypeScript every time the app starts and needs more memory than the Small size allows. Build to JavaScript and start that, for example \"start\": \"node dist/main.js\", or pick a bigger size."]);
            }
        }

        $background = JsTooling::backgroundCommand($facts, (string) $name);

        if ($background !== null) {
            return $this->proposal('js', null, $runtime, null, $sources, ['framework' => "The {$name} script runs {$background}, which puts the app in the background, where Coresmith can't keep it running. Start it in the foreground instead, for example \"start\": \"node server.js\" or \"pm2-runtime server.js\"."]);
        }

        $server = array_values(array_filter(self::SERVER_PACKAGES, fn(string $package): bool => $facts->dependency($package) !== null))[0] ?? null;

        return $server === null
            ? $this->proposal('js', null, $runtime, null, $sources, ['framework' => self::NO_SERVER])
            : $this->proposal('js', '', $runtime, null, [...$sources, 'framework' => "The {$name} script in package.json, with {$server}"]);
    }

    /**
     * @return array{type: ?string, framework: ?string, js_runtime: ?string, web: ?string, sources: array<string, string>, unsettled: array<string, string>}
     */
    private function deno(RepositoryFacts $facts): array
    {
        $sources = ['type' => 'From deno.json', 'js_runtime' => 'From deno.json'];

        if ($facts->denoTask('start') || $facts->exists('main.ts')) {
            return $this->proposal('js', '', 'deno', null, $sources);
        }

        $library = isset($facts->json('deno.json')['exports']) || isset($facts->json('deno.jsonc')['exports']);

        return $this->proposal('js', null, 'deno', null, $sources, ['framework' => $library ? self::DENO_LIBRARY : self::DENO_NO_START]);
    }

    /**
     * @param  array<string, string>  $sources
     * @return array{type: ?string, framework: ?string, js_runtime: ?string, web: ?string, sources: array<string, string>, unsettled: array<string, string>}
     */
    private function unbuilt(string $runtime, array $sources, string $package, string $command, string $label): array
    {
        return $this->proposal('js', null, $runtime, null, $sources, ['framework' => "{$package} is in package.json, but the build script doesn't run {$command}, so Coresmith can't tell this is a {$label} app."]);
    }

    /**
     * @param  array<int|string, mixed>  $require
     */
    private function symfony(RepositoryFacts $facts, array $require): ?string
    {
        return match (true) {
            array_key_exists('symfony/framework-bundle', $require) => $this->composerSource($facts, 'symfony/framework-bundle', $require['symfony/framework-bundle']),
            $facts->locked('symfony/framework-bundle') !== null => 'symfony/framework-bundle ' . $facts->locked('symfony/framework-bundle') . ' in composer.lock',
            $facts->exists('symfony.lock'), $facts->exists('config/bundles.php') => 'symfony.lock and config/bundles.php',
            $facts->exists('bin/console') && array_filter(array_keys($require), fn(int|string $package): bool => array_filter(self::SYMFONY_FAMILY, fn(string $family): bool => str_starts_with((string) $package, $family)) !== []) !== [] => 'bin/console with Symfony packages in composer.json',
            default => null,
        };
    }

    /**
     * @return array{type: ?string, framework: ?string, js_runtime: ?string, web: ?string, sources: array<string, string>, unsettled: array<string, string>}|null
     */
    private function html(RepositoryFacts $facts): ?array
    {
        foreach (['index.html' => '/', 'public/index.html' => '/public'] as $file => $web) {
            if ($facts->exists($file)) {
                return $this->proposal('static', '', null, $web, ['type' => "From {$file}", 'web_directory' => $web === '/' ? "index.html is at the repository's root" : 'index.html is in /public']);
            }
        }

        return null;
    }

    /**
     * @param  array<string, string>  $sources
     * @return array{type: ?string, framework: ?string, js_runtime: ?string, web: ?string, sources: array<string, string>, unsettled: array<string, string>}
     */
    private function staticBuild(?string $output, array $sources, string $unknown): array
    {
        return $output === null
            ? $this->proposal('static', '', null, null, $sources, ['web_directory' => $unknown])
            : $this->proposal('static', '', null, $output, [...$sources, 'web_directory' => 'The build writes to ' . ltrim($output, '/')]);
    }

    /**
     * @param  array<string, string>  $sources
     * @param  array<string, string>  $unsettled
     * @return array{type: ?string, framework: ?string, js_runtime: ?string, web: ?string, sources: array<string, string>, unsettled: array<string, string>}
     */
    private function proposal(?string $type, ?string $framework, ?string $runtime, ?string $web, array $sources, array $unsettled = []): array
    {
        return ['type' => $type, 'framework' => $framework, 'js_runtime' => $runtime, 'web' => $web, 'sources' => $sources, 'unsettled' => $unsettled];
    }

    /**
     * @return array{type: ?string, framework: ?string, js_runtime: ?string, web: ?string, sources: array<string, string>, unsettled: array<string, string>}
     */
    private function unsure(string $field, string $reason): array
    {
        return $this->proposal(null, null, null, null, [], [$field => $reason]);
    }

    /**
     * @return array{string, string}
     */
    private function jsRuntime(RepositoryFacts $facts): array
    {
        $lockfile = $facts->lockfile();

        if ($lockfile !== null) {
            return [self::JS_LOCKFILES[$lockfile], "From {$lockfile}"];
        }

        if (in_array('deno.lock', $facts->lockfiles, true)) {
            return ['deno', 'From deno.lock'];
        }

        return ['node', 'No lockfile, so Node, which every npm package supports'];
    }

    /**
     * @return array<string, string>
     */
    private function oldBundler(RepositoryFacts $facts): array
    {
        $lock = $facts->file('Gemfile.lock');

        if ($lock === null) {
            return [];
        }

        $bundler = preg_match('/^BUNDLED WITH\R\s+(\d+\.\d+[\w.]*)/m', $lock, $matches) === 1 ? $matches[1] : null;

        return match (true) {
            $bundler === null => ['runtime_version' => "Gemfile.lock doesn't say which Bundler wrote it, so it predates Bundler 1.10. Coresmith builds Ruby 3.2 and newer, where locks that old often fail to install. Run bundle update --bundler, then commit Gemfile.lock."],
            version_compare($bundler, '2.4', '<') => ['runtime_version' => "Gemfile.lock was written by Bundler {$bundler}. Coresmith builds Ruby 3.2 and newer, where locks that old often fail to install. Run bundle update --bundler, then commit Gemfile.lock."],
            default => [],
        };
    }

    private function viteOnly(RepositoryFacts $facts): bool
    {
        $start = (string) ($facts->json('package.json')['scripts']['start'] ?? '');

        return trim($start) === '' || preg_match('~^\s*vite(\s|$)~', $start) === 1;
    }

    private function otherServer(RepositoryFacts $facts): bool
    {
        return $facts->has('Gemfile') || $facts->has('package.json') || $facts->has('deno.json') || $facts->has('deno.jsonc');
    }

    private function gemfileNames(RepositoryFacts $facts, string $gem): bool
    {
        return preg_match('/^\s*gem\s+[\'"]' . preg_quote($gem, '/') . '[\'"]/m', (string) $facts->file('Gemfile')) === 1;
    }

    private function composerSource(RepositoryFacts $facts, string $package, mixed $constraint): string
    {
        $locked = $facts->locked($package);

        return $locked === null ? "{$package} " . (is_string($constraint) ? $constraint : '') . ' in composer.json' : "{$package} {$locked} in composer.lock";
    }

    private function indexSource(string $index): string
    {
        return $index === '/' ? "index.php is at the repository's root" : "index.php is in {$index}";
    }

    /**
     * @param  Closure(string): list<string>  $installed
     */
    private function stack(Application $application, RepositoryFacts $facts, Closure $installed): ?Stack
    {
        try {
            return $this->resolver->resolve($application, $facts, $installed);
        } catch (RuntimeUnavailable) {
            return null;
        }
    }

    private function runtimeOf(ApplicationType $type, ?string $jsRuntime, ?Stack $stack): ?string
    {
        return $type === ApplicationType::Static ? $stack?->tooling?->runtime() : $type->runtime($jsRuntime);
    }
}
