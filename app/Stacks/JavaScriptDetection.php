<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Stacks;

use App\Services\Runtime\JsTooling;
use App\Services\Runtime\RepositoryFacts;
use App\Stacks\JsPresets\JsPresets;

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

    public function __construct(private JsPresets $presets) {}

    /**
     * @return array{type: ?string, framework: ?string, js_runtime: ?string, web: ?string, sources: array<string, string>, unsettled: array<string, string>, fields?: array<string, ?string>, options?: array<string, list<string>>}|null
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
        $output = $this->presets->staticOutput($facts);
        $build = (string) ($facts->json('package.json')['scripts']['build'] ?? '');
        $presets = $this->presets->all();

        foreach ($presets as $preset) {
            $command = $preset->claims($facts, $build) ? $preset->unbuilt($build) : null;

            if ($command !== null) {
                return $this->unbuilt($runtime, $sources, $preset->package(), $command, $preset->label());
            }
        }

        foreach ($presets as $preset) {
            if ($preset->claims($facts, $build)) {
                return $preset->propose($facts, $runtime, $sources, $output);
            }
        }

        return $this->fromStartScript($facts, $runtime, $sources);
    }

    /**
     * @param  array<string, string>  $sources
     * @return array{type: ?string, framework: ?string, js_runtime: ?string, web: ?string, sources: array<string, string>, unsettled: array<string, string>, fields?: array<string, ?string>, options?: array<string, list<string>>}
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
     * @return array{type: ?string, framework: ?string, js_runtime: ?string, web: ?string, sources: array<string, string>, unsettled: array<string, string>, fields?: array<string, ?string>, options?: array<string, list<string>>}
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
     * @return array{type: ?string, framework: ?string, js_runtime: ?string, web: ?string, sources: array<string, string>, unsettled: array<string, string>, fields?: array<string, ?string>, options?: array<string, list<string>>}
     */
    private function unbuilt(string $runtime, array $sources, string $package, string $command, string $label): array
    {
        return Proposal::make('js', null, $runtime, null, $sources, ['framework' => "{$package} is in package.json, but the build script doesn't run {$command}, so Coresmith can't tell this is " . (preg_match('/^[AEIOU]/i', $label) === 1 ? 'an' : 'a') . " {$label} app."]);
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
}
