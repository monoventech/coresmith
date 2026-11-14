<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Stacks;

use App\Models\Application;
use App\Services\Detection\Blocker;
use App\Services\Runtime\Catalog;
use App\Services\Runtime\JsTooling;
use App\Services\Runtime\RepositoryFacts;

final readonly class JavaScriptChecks
{
    public function __construct(private JsFrameworks $frameworks, private Catalog $catalog) {}

    /**
     * @return list<Blocker>
     */
    public function checks(Application $choice, string $framework, RepositoryFacts $facts, ?string $arch): array
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

        $blockers = match ($framework) {
            'sveltekit' => $this->svelteKit($facts),
            'nextjs', 'next' => $this->next($facts),
            'nuxt' => $this->nuxt($facts),
            'astro' => $this->astro($facts),
            default => $this->started($facts),
        };
        $port = $this->fixedPort($facts);
        $blockers = $port === null ? $blockers : [...$blockers, $port];

        return $facts->dependency('fibers') === null
            ? $blockers
            : [...$blockers, new Blocker('repository', 'package.json depends on fibers, which does not build on Node.js 16 or later, and Coresmith installs Node.js ' . $this->catalog->range('nodejs') . '.', 'Remove fibers from package.json. Its author retired it when Node.js 16 came out.')];
    }

    private function fixedPort(RepositoryFacts $facts): ?Blocker
    {
        $script = JsTooling::startScriptFor($facts);
        $command = $script === null ? null : ($facts->json('package.json')['scripts'][$script] ?? null);

        if (!is_string($command) || preg_match('/(?:^|[\s;&|(])(?:(?:-p|--port)(?:\s+|=)|PORT=)(\d{2,5})(?=$|[\s;&|)])/', $command, $matches) !== 1) {
            return null;
        }

        return new Blocker('repository', "The {$script} script sets port {$matches[1]}, but Coresmith gives each app its own port in PORT, so visitors would get a 502.", "Take the port out of the {$script} script so the app listens on PORT, which Coresmith sets.");
    }

    /**
     * @return list<Blocker>
     */
    private function svelteKit(RepositoryFacts $facts): array
    {
        if ($facts->dependency('@sveltejs/kit') === null) {
            return [new Blocker('framework', "@sveltejs/kit isn't in package.json, so this isn't a SvelteKit app.", 'Pick the framework the app uses, or API or other.')];
        }

        $adapter = $this->frameworks->svelteAdapter($facts);

        return match (true) {
            $adapter === null, in_array($adapter, JsFrameworks::SERVER_ADAPTERS, true) => [],
            $adapter === '@sveltejs/adapter-static' => [new Blocker('type', 'svelte.config.js uses @sveltejs/adapter-static, so the build is static files, not a server.', 'Pick Static site with the web root /build.')],
            default => [new Blocker('framework', "svelte.config.js uses {$adapter}, which builds the app for that hosting platform's own servers.", 'Install @sveltejs/adapter-node, use it in svelte.config.js, then commit both.')],
        };
    }

    /**
     * @return list<Blocker>
     */
    private function next(RepositoryFacts $facts): array
    {
        return match (true) {
            $facts->dependency('next') === null => [new Blocker('framework', "next isn't in package.json, so this isn't a Next.js app.", 'Pick the framework the app uses, or API or other.')],
            $this->frameworks->nextExport($facts) => [new Blocker('type', "next.config sets output: 'export', so the build is static files, not a server.", 'Pick Static site with the web root /out.')],
            !$facts->script('start') => [new Blocker('repository', 'There is no start script in package.json, so there is nothing to start the Next.js server with.', 'Add "start": "next start" to the scripts in package.json.')],
            default => [],
        };
    }

    /**
     * @return list<Blocker>
     */
    private function nuxt(RepositoryFacts $facts): array
    {
        $preset = $this->frameworks->nitroPreset($facts);

        return match (true) {
            $facts->dependency('nuxt') === null => [new Blocker('framework', "nuxt isn't in package.json, so this isn't a Nuxt app.", 'Pick the framework the app uses, or API or other.')],
            $this->frameworks->nuxtGenerate($facts) => [new Blocker('type', 'The build script runs nuxt generate, so the build is static files, not a server.', 'Pick Static site with the web root /.output/public.')],
            !$facts->script('build') => [new Blocker('repository', 'There is no build script in package.json, so the Nuxt server is never built.', 'Add "build": "nuxt build" to the scripts in package.json.')],
            $preset !== null && $preset[1] => [new Blocker('framework', "Nitro's preset is {$preset[0]}, so nuxt build makes the app for that host, not the Node server Coresmith runs.", 'Remove the preset or set it to node-server. If the app is static, pick Static site with the web root /.output/public.')],
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
            !$this->frameworks->astroServer($facts) => [new Blocker('type', 'This Astro app has no @astrojs/node adapter, so the build is static files, not a server.', 'Pick Static site with the web root /dist, or add @astrojs/node to serve pages on demand.')],
            default => [],
        };
    }

    /**
     * @return list<Blocker>
     */
    private function started(RepositoryFacts $facts): array
    {
        return JsTooling::startScriptFor($facts) !== null || JsTooling::safeMain($facts->json('package.json')['main'] ?? null) !== null || $facts->exists('server.js')
            ? []
            : [new Blocker('repository', 'There is no start script and no main file in package.json, so Coresmith has nothing to start.', 'Add a start script to package.json, for example "start": "node server.js".')];
    }
}
