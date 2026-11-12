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
use App\Services\Runtime\RepositoryFacts;
use App\Services\Runtime\RepositoryProbe;
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

    private const JS_LOCKFILES = ['bun.lock' => 'bun', 'bun.lockb' => 'bun', 'package-lock.json' => 'node', 'pnpm-lock.yaml' => 'node', 'yarn.lock' => 'node'];

    public function __construct(
        private readonly StackChecks $checks,
        private readonly StackResolver $resolver,
        private readonly Catalog $catalog,
    ) {}

    /**
     * @param  Closure(string): list<string>  $installed
     */
    public function detect(RepositoryFacts $facts, Closure $installed): DetectedStack
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

        return new DetectedStack(
            $proposal['type'],
            $proposal['framework'],
            $proposal['js_runtime'],
            $proposal['web'],
            $sources,
            $unsettled,
            $this->checks->blockers($application, $facts, $installed),
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

        foreach (['laravel/framework' => ['laravel', 'Laravel'], 'symfony/framework-bundle' => ['symfony', 'Symfony']] as $package => [$framework, $label]) {
            if (array_key_exists($package, $require)) {
                return $this->proposal('php', $framework, null, '/public', [
                    'type' => 'From composer.json',
                    'framework' => $this->composerSource($facts, $package, $require[$package]),
                    'web_directory' => "{$label} serves public/index.php",
                ]);
            }
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

        $sources = ['type' => $facts->has('Gemfile.lock') ? 'From Gemfile and Gemfile.lock' : 'From Gemfile'];

        foreach (['rails' => 'bin/rails', 'sinatra' => 'config.ru'] as $gem => $marker) {
            $version = $facts->gem($gem);

            if ($version === null && !$this->gemfileNames($facts, $gem)) {
                continue;
            }

            if ($gem === 'rails' && !$facts->exists($marker)) {
                return $this->proposal('ruby', null, null, null, $sources, ['framework' => 'Gemfile names rails, but there is no bin/rails, so this looks like a Rails engine or gem, not an app.']);
            }

            return $this->proposal('ruby', $gem, null, null, [...$sources, 'framework' => $version === null ? "{$gem} in Gemfile" : "{$gem} {$version} in Gemfile.lock"]);
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
            return $deno ? $this->proposal('js', '', 'deno', null, ['type' => 'From deno.json', 'js_runtime' => 'From deno.json']) : null;
        }

        if ($facts->workspace()) {
            return $this->unsure('type', self::WORKSPACE);
        }

        [$runtime, $runtimeSource] = $this->jsRuntime($facts);
        $sources = ['type' => 'From package.json', 'js_runtime' => $runtimeSource];
        $output = $this->checks->staticOutput($facts);

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
            return $this->checks->nuxtGenerate($facts)
                ? $this->staticBuild($output, ['type' => 'From the nuxt generate build script'], '')
                : $this->proposal('js', 'nuxt', $runtime, null, [...$sources, 'framework' => 'nuxt in package.json']);
        }

        if ($facts->dependency('astro') !== null) {
            return $this->checks->astroServer($facts)
                ? $this->proposal('js', 'astro', $runtime, null, [...$sources, 'framework' => 'astro with @astrojs/node in package.json'])
                : $this->staticBuild($output, ['type' => 'From astro in package.json, with no server adapter'], 'astro.config sets its own outDir, so Coresmith can\'t be sure where the build goes. Set the web root to that folder.');
        }

        if ($facts->dependency('vite') !== null && str_contains((string) ($facts->json('package.json')['scripts']['build'] ?? ''), 'vite build') && $this->viteOnly($facts)) {
            return $this->staticBuild($output, ['type' => 'From the vite build script in package.json'], 'vite.config sets its own outDir, so Coresmith can\'t be sure where the build goes. Set the web root to that folder.');
        }

        return $this->proposal('js', '', $runtime, null, [...$sources, 'framework' => $facts->script('start') ? 'The start script in package.json' : 'The main file in package.json']);
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
