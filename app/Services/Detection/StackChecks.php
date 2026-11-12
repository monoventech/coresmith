<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Detection;

use App\Enums\ApplicationType;
use App\Exceptions\RuntimeUnavailable;
use App\Models\Application;
use App\Services\Runtime\JsTooling;
use App\Services\Runtime\RepositoryFacts;
use App\Services\Runtime\StackResolver;
use Closure;

final class StackChecks
{
    public const SERVER_ADAPTERS = ['@sveltejs/adapter-node', 'svelte-adapter-bun'];

    private const PHP_INDEXES = ['public' => 'public/index.php', 'web' => 'web/index.php', '' => 'index.php'];

    private const STATIC_INDEXES = ['' => 'index.html', 'public' => 'public/index.html'];

    public function __construct(private readonly StackResolver $resolver) {}

    /**
     * @param  Closure(string): list<string>  $installed
     * @return list<Blocker>
     */
    public function blockers(Application $choice, RepositoryFacts $facts, Closure $installed): array
    {
        if (!$facts->complete) {
            return [];
        }

        $framework = strtolower((string) $choice->framework);

        $blockers = match ($choice->type) {
            ApplicationType::Php => $this->php($choice, $framework, $facts),
            ApplicationType::Ruby => $this->ruby($framework, $facts),
            ApplicationType::Js => $this->javascript($choice, $framework, $facts),
            ApplicationType::Static => $this->static($choice, $facts),
            default => [],
        };

        return [...$blockers, ...$this->runtime($choice, $facts, $installed)];
    }

    public function svelteAdapter(RepositoryFacts $facts): ?string
    {
        foreach (['svelte.config.js', 'svelte.config.ts'] as $file) {
            if (preg_match('~(?:from|require\()\s*[\'"](@sveltejs/adapter-[a-z0-9-]+|svelte-adapter-bun)[\'"]~', (string) $facts->file($file), $matches) === 1) {
                return $matches[1];
            }
        }

        return null;
    }

    public function nextExport(RepositoryFacts $facts): bool
    {
        return $this->config($facts, ['next.config.js', 'next.config.mjs', 'next.config.ts'], '~output\s*:\s*[\'"]export[\'"]~');
    }

    public function nuxtGenerate(RepositoryFacts $facts): bool
    {
        return str_contains((string) ($facts->json('package.json')['scripts']['build'] ?? ''), 'nuxt generate');
    }

    public function astroServer(RepositoryFacts $facts): bool
    {
        return $facts->dependency('@astrojs/node') !== null && $this->config($facts, ['astro.config.mjs', 'astro.config.ts', 'astro.config.js'], '~@astrojs/node~');
    }

    public function staticOutput(RepositoryFacts $facts): ?string
    {
        return match (true) {
            $facts->dependency('@sveltejs/kit') !== null => $this->svelteAdapter($facts) === '@sveltejs/adapter-static' && !$this->config($facts, ['svelte.config.js', 'svelte.config.ts'], '~pages\s*:~') ? '/build' : null,
            $facts->dependency('next') !== null => $this->nextExport($facts) && !$this->config($facts, ['next.config.js', 'next.config.mjs', 'next.config.ts'], '~distDir\s*:~') ? '/out' : null,
            $facts->dependency('nuxt') !== null => $this->nuxtGenerate($facts) ? '/.output/public' : null,
            $facts->dependency('astro') !== null => !$this->astroServer($facts) && !$this->config($facts, ['astro.config.mjs', 'astro.config.ts', 'astro.config.js'], '~outDir\s*:~') ? '/dist' : null,
            $facts->dependency('vite') !== null => $this->config($facts, ['vite.config.ts', 'vite.config.js'], '~outDir\s*:~') ? null : '/dist',
            default => null,
        };
    }

    /**
     * @param  list<string>  $files
     */
    public function config(RepositoryFacts $facts, array $files, string $pattern): bool
    {
        foreach ($files as $file) {
            if (preg_match($pattern, (string) $facts->file($file)) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<Blocker>
     */
    private function php(Application $choice, string $framework, RepositoryFacts $facts): array
    {
        $blockers = [];
        $name = $framework === 'symfony' ? 'Symfony' : 'Laravel';

        if (in_array($framework, ['laravel', 'symfony'], true) && !$facts->has('composer.json')) {
            $blockers[] = new Blocker('framework', "There is no composer.json, so this isn't a {$name} app at the repository's root.", 'Pick Plain PHP.');
        } elseif ($framework === 'laravel' && !$facts->exists('artisan')) {
            $blockers[] = new Blocker('framework', "There is no artisan file at the repository's root, so this isn't a Laravel app.", 'Pick Plain PHP, or Symfony if it uses Symfony.');
        }

        $web = trim((string) $choice->web_directory, '/');
        $index = self::PHP_INDEXES[$web] ?? null;

        if ($index !== null && !$facts->exists($index)) {
            $found = array_search(true, array_map(fn(string $path): bool => $facts->exists($path), self::PHP_INDEXES), true);
            $blockers[] = new Blocker(
                'web_directory',
                "There is no {$index}, so the web root /{$web} would serve nothing.",
                is_string($found) ? 'Set the web root to /' . $found . ', where index.php is.' : 'Set the web root to the folder that holds index.php.',
            );
        }

        return $blockers;
    }

    /**
     * @return list<Blocker>
     */
    private function ruby(string $framework, RepositoryFacts $facts): array
    {
        if (!$facts->has('Gemfile')) {
            return [new Blocker('type', "There is no Gemfile at the repository's root, so this isn't a Ruby app.", 'Pick the language the repository uses.')];
        }

        if (!$facts->has('Gemfile.lock')) {
            return [new Blocker('repository', 'There is no Gemfile.lock. Coresmith installs gems in deployment mode, which needs it.', 'Commit Gemfile.lock: run bundle install, then commit the file it writes.')];
        }

        $blockers = [];

        if ($facts->gem('puma') === null) {
            $blockers[] = new Blocker('repository', "Puma isn't in Gemfile.lock. Coresmith starts Ruby apps with Puma.", 'Add gem "puma" to the Gemfile, run bundle install and commit Gemfile.lock.');
        }

        if ($framework === 'sinatra' && !$facts->exists('config.ru')) {
            $blockers[] = new Blocker('framework', 'There is no config.ru, so Puma has no app to start.', 'Add a config.ru that runs the app, for example: run Sinatra::Application.');
        } elseif ($framework !== 'sinatra' && !$facts->exists('bin/rails')) {
            $blockers[] = new Blocker('framework', "There is no bin/rails, so this isn't a Rails app at the repository's root.", 'Pick Sinatra if it uses Sinatra.');
        }

        return $blockers;
    }

    /**
     * @return list<Blocker>
     */
    private function javascript(Application $choice, string $framework, RepositoryFacts $facts): array
    {
        $runtime = $choice->js_runtime ?: 'bun';

        if ($runtime === 'deno') {
            if (!$facts->has('deno.json') && !$facts->has('deno.jsonc') && !$facts->has('package.json')) {
                return [new Blocker('js_runtime', 'There is no deno.json or package.json, so there is nothing for Deno to run.', 'Pick the runtime the repository uses.')];
            }

            return $facts->denoTask('start') || $facts->exists('main.ts')
                ? []
                : [new Blocker('repository', 'There is no start task in deno.json, and no main.ts to run instead.', 'Add a start task to deno.json, for example "start": "deno run --allow-net main.ts".')];
        }

        if (!$facts->has('package.json')) {
            return [new Blocker(
                'js_runtime',
                'There is no package.json, so there is nothing for ' . ($runtime === 'node' ? 'Node' : 'Bun') . ' to install or start.',
                $facts->has('deno.json') || $facts->has('deno.jsonc') ? 'Pick Deno, which runs deno.json.' : 'Pick the language the repository uses.',
            )];
        }

        return match ($framework) {
            'sveltekit' => $this->svelteKit($facts),
            'nextjs', 'next' => $this->next($facts),
            'nuxt' => $this->nuxt($facts),
            'astro' => $this->astro($facts),
            default => $this->started($facts),
        };
    }

    /**
     * @return list<Blocker>
     */
    private function svelteKit(RepositoryFacts $facts): array
    {
        if ($facts->dependency('@sveltejs/kit') === null) {
            return [new Blocker('framework', "@sveltejs/kit isn't in package.json, so this isn't a SvelteKit app.", 'Pick the framework the app uses, or API or other.')];
        }

        $adapter = $this->svelteAdapter($facts);

        return match (true) {
            $adapter === null, in_array($adapter, self::SERVER_ADAPTERS, true) => [],
            $adapter === '@sveltejs/adapter-static' => [new Blocker('type', 'svelte.config.js uses @sveltejs/adapter-static, so the build is static files, not a server.', 'Pick Static site with the web root /build.')],
            default => [new Blocker('framework', "svelte.config.js uses {$adapter}, which builds for a hosting platform, not for a server Coresmith runs.", 'Install @sveltejs/adapter-node, use it in svelte.config.js, then commit both.')],
        };
    }

    /**
     * @return list<Blocker>
     */
    private function next(RepositoryFacts $facts): array
    {
        return match (true) {
            $facts->dependency('next') === null => [new Blocker('framework', "next isn't in package.json, so this isn't a Next.js app.", 'Pick the framework the app uses, or API or other.')],
            $this->nextExport($facts) => [new Blocker('type', "next.config sets output: 'export', so the build is static files, not a server.", 'Pick Static site with the web root /out.')],
            !$facts->script('start') => [new Blocker('repository', 'There is no start script in package.json, so there is nothing to start the Next.js server with.', 'Add "start": "next start" to the scripts in package.json.')],
            default => [],
        };
    }

    /**
     * @return list<Blocker>
     */
    private function nuxt(RepositoryFacts $facts): array
    {
        return match (true) {
            $facts->dependency('nuxt') === null => [new Blocker('framework', "nuxt isn't in package.json, so this isn't a Nuxt app.", 'Pick the framework the app uses, or API or other.')],
            $this->nuxtGenerate($facts) => [new Blocker('type', 'The build script runs nuxt generate, so the build is static files, not a server.', 'Pick Static site with the web root /.output/public.')],
            !$facts->script('build') => [new Blocker('repository', 'There is no build script in package.json, so the Nuxt server is never built.', 'Add "build": "nuxt build" to the scripts in package.json.')],
            default => [],
        };
    }

    /**
     * @return list<Blocker>
     */
    private function astro(RepositoryFacts $facts): array
    {
        return match (true) {
            $facts->dependency('astro') === null => [new Blocker('framework', "astro isn't in package.json, so this isn't an Astro app.", 'Pick the framework the app uses, or API or other.')],
            !$this->astroServer($facts) => [new Blocker('type', 'This Astro app has no @astrojs/node adapter, so the build is static files, not a server.', 'Pick Static site with the web root /dist, or add @astrojs/node to serve pages on demand.')],
            default => [],
        };
    }

    /**
     * @return list<Blocker>
     */
    private function started(RepositoryFacts $facts): array
    {
        return $facts->script('start') || JsTooling::safeMain($facts->json('package.json')['main'] ?? null) !== null
            ? []
            : [new Blocker('repository', 'There is no start script and no main file in package.json, so Coresmith has nothing to start.', 'Add a start script to package.json, for example "start": "node server.js".')];
    }

    /**
     * @return list<Blocker>
     */
    private function static(Application $choice, RepositoryFacts $facts): array
    {
        $web = trim((string) $choice->web_directory, '/');

        if ($facts->script('build')) {
            $output = $this->staticOutput($facts);

            return $output !== null && $output !== '/' . $web
                ? [new Blocker('web_directory', "The build writes to {$output}, so the web root /{$web} would be empty.", "Set the web root to {$output}.")]
                : [];
        }

        $index = self::STATIC_INDEXES[$web] ?? null;

        if ($index === null || $facts->exists($index)) {
            return [];
        }

        $found = array_search(true, array_map(fn(string $path): bool => $facts->exists($path), self::STATIC_INDEXES), true);

        return [new Blocker(
            'web_directory',
            "There is no {$index}, so the web root /{$web} would serve nothing.",
            is_string($found) ? 'Set the web root to /' . $found . ', where index.html is.' : 'Set the web root to the folder that holds index.html.',
        )];
    }

    /**
     * @param  Closure(string): list<string>  $installed
     * @return list<Blocker>
     */
    private function runtime(Application $choice, RepositoryFacts $facts, Closure $installed): array
    {
        if ($choice->type === null) {
            return [];
        }

        try {
            $this->resolver->resolve($choice, $facts, $installed);
        } catch (RuntimeUnavailable $refusal) {
            return [new Blocker('runtime_version', $refusal->getMessage())];
        }

        return [];
    }
}
