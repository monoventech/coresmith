<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Stacks;

use App\Services\Runtime\JsTooling;
use App\Services\Runtime\RepositoryFacts;

final readonly class JavaScriptDetection
{
    public const WORKSPACE = 'This is a workspace root: it lists other packages, and Coresmith deploys one app per folder.';

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

    private const JS_LOCKFILES = ['bun.lock' => 'bun', 'bun.lockb' => 'bun', 'package-lock.json' => 'node', 'pnpm-lock.yaml' => 'node', 'yarn.lock' => 'node'];

    public function __construct(private JsFrameworks $frameworks) {}

    /**
     * @return array{type: ?string, framework: ?string, js_runtime: ?string, web: ?string, sources: array<string, string>, unsettled: array<string, string>}|null
     */
    public function detect(RepositoryFacts $facts): ?array
    {
        $deno = $facts->has('deno.json') || $facts->has('deno.jsonc');

        if (!$facts->has('package.json')) {
            return $deno ? $this->deno($facts) : null;
        }

        if ($facts->workspace()) {
            return Proposal::unsure('type', self::WORKSPACE);
        }

        [$runtime, $runtimeSource] = $this->jsRuntime($facts);
        $sources = ['type' => 'From package.json', 'js_runtime' => $runtimeSource];
        $output = $this->frameworks->staticOutput($facts);

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
            $adapter = $this->frameworks->svelteAdapter($facts);

            if ($adapter === '@sveltejs/adapter-static') {
                return Proposal::staticBuild($output, ['type' => 'From @sveltejs/adapter-static in svelte.config.js'], 'Coresmith couldn\'t tell where adapter-static writes, because svelte.config.js sets its own pages folder. Set the web root to that folder.');
            }

            return Proposal::make('js', 'sveltekit', $runtime, null, [...$sources, 'framework' => $adapter === null ? '@sveltejs/kit in package.json' : "@sveltejs/kit with {$adapter}"], $adapter === null ? ['framework' => "Coresmith couldn't tell which adapter svelte.config.js uses. SvelteKit needs @sveltejs/adapter-node to run on a server."] : []);
        }

        if ($facts->dependency('next') !== null) {
            return $this->frameworks->nextExport($facts)
                ? Proposal::staticBuild($output, ['type' => "From output: 'export' in next.config"], 'next.config sets its own distDir, so Coresmith can\'t be sure where the export goes. Set the web root to that folder.')
                : Proposal::make('js', 'nextjs', $runtime, null, [...$sources, 'framework' => 'next in package.json']);
        }

        if ($facts->dependency('nuxt') !== null) {
            $preset = $this->frameworks->nitroPreset($facts);

            return $this->frameworks->nuxtGenerate($facts)
                ? Proposal::staticBuild($output, ['type' => 'From the nuxt generate build script'], '')
                : Proposal::make('js', 'nuxt', $runtime, null, [...$sources, 'framework' => 'nuxt in package.json'], match (true) {
                    $preset !== null && !$preset[1] => ['framework' => "nuxt.config can set Nitro's preset to {$preset[0]}, which makes the app for that host, not the Node server Coresmith runs. Set the preset to node-server."],
                    $facts->dependency('@nuxthub/core') !== null => ['framework' => '@nuxthub/core builds the app for Cloudflare, not the Node server Coresmith runs.'],
                    default => [],
                });
        }

        if ($facts->dependency('astro') !== null) {
            return $this->frameworks->astroServer($facts)
                ? Proposal::make('js', 'astro', $runtime, null, [...$sources, 'framework' => 'astro with @astrojs/node in package.json'])
                : Proposal::staticBuild($output, ['type' => 'From astro in package.json, with no server adapter'], 'astro.config sets its own outDir, so Coresmith can\'t be sure where the build goes. Set the web root to that folder.');
        }

        if ($facts->dependency('react-scripts') !== null && str_contains($build, 'react-scripts build')) {
            return Proposal::staticBuild('/build', ['type' => 'From the react-scripts build script in package.json'], '');
        }

        if ($facts->dependency('vite') !== null && str_contains($build, 'vite build') && $this->viteOnly($facts)) {
            return Proposal::staticBuild($output, ['type' => 'From the vite build script in package.json'], 'vite.config sets its own outDir, so Coresmith can\'t be sure where the build goes. Set the web root to that folder.');
        }

        return $this->fromStartScript($facts, $runtime, $sources);
    }

    /**
     * @param  array<string, string>  $sources
     * @return array{type: ?string, framework: ?string, js_runtime: ?string, web: ?string, sources: array<string, string>, unsettled: array<string, string>}
     */
    private function fromStartScript(RepositoryFacts $facts, string $runtime, array $sources): array
    {
        $name = JsTooling::startScriptFor($facts);
        $start = $name === null ? '' : trim((string) ($facts->json('package.json')['scripts'][$name] ?? ''));

        if ($start === '') {
            return Proposal::make('js', null, $runtime, null, $sources, ['framework' => isset($facts->json('package.json')['main']) ? self::MAIN_ONLY : self::NO_START]);
        }

        foreach (self::DEVELOPMENT_TOOLS as $tool) {
            if (preg_match('/(^|[\s;&|(])' . preg_quote($tool, '/') . '(\s|$)/', $start) === 1) {
                return Proposal::make('js', null, $runtime, null, $sources, ['framework' => "The {$name} script runs {$tool}, which isn't a production server. Add a start script that runs the app, for example \"start\": \"node server.js\"."]);
            }
        }

        foreach (self::COMPILES_AT_START as $tool) {
            if (preg_match('/(^|[\s;&|(])' . preg_quote($tool, '/') . '(\s|$)/', $start) === 1) {
                return Proposal::make('js', null, $runtime, null, $sources, ['framework' => "The {$name} script runs {$tool}, which compiles TypeScript every time the app starts and needs more memory than the Small size allows. Build to JavaScript and start that, for example \"start\": \"node dist/main.js\", or pick a bigger size."]);
            }
        }

        $background = JsTooling::backgroundCommand($facts, (string) $name);

        if ($background !== null) {
            return Proposal::make('js', null, $runtime, null, $sources, ['framework' => "The {$name} script runs {$background}, which puts the app in the background, where Coresmith can't keep it running. Start it in the foreground instead, for example \"start\": \"node server.js\" or \"pm2-runtime server.js\"."]);
        }

        $server = array_values(array_filter(self::SERVER_PACKAGES, fn(string $package): bool => $facts->dependency($package) !== null))[0] ?? null;

        return $server === null
            ? Proposal::make('js', null, $runtime, null, $sources, ['framework' => self::NO_SERVER])
            : Proposal::make('js', '', $runtime, null, [...$sources, 'framework' => "The {$name} script in package.json, with {$server}"]);
    }

    /**
     * @return array{type: ?string, framework: ?string, js_runtime: ?string, web: ?string, sources: array<string, string>, unsettled: array<string, string>}
     */
    private function deno(RepositoryFacts $facts): array
    {
        $sources = ['type' => 'From deno.json', 'js_runtime' => 'From deno.json'];

        if ($facts->denoTask('start') || $facts->exists('main.ts')) {
            return Proposal::make('js', '', 'deno', null, $sources);
        }

        $library = isset($facts->json('deno.json')['exports']) || isset($facts->json('deno.jsonc')['exports']);

        return Proposal::make('js', null, 'deno', null, $sources, ['framework' => $library ? self::DENO_LIBRARY : self::DENO_NO_START]);
    }

    /**
     * @param  array<string, string>  $sources
     * @return array{type: ?string, framework: ?string, js_runtime: ?string, web: ?string, sources: array<string, string>, unsettled: array<string, string>}
     */
    private function unbuilt(string $runtime, array $sources, string $package, string $command, string $label): array
    {
        return Proposal::make('js', null, $runtime, null, $sources, ['framework' => "{$package} is in package.json, but the build script doesn't run {$command}, so Coresmith can't tell this is a {$label} app."]);
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
}
