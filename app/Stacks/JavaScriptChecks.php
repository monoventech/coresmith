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
use App\Stacks\JsPresets\JsPresets;

final readonly class JavaScriptChecks
{
    public function __construct(private JsPresets $presets, private Catalog $catalog) {}

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

        $blockers = $this->presets->find($framework)?->blockers($facts) ?? $this->started($facts);
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
    private function started(RepositoryFacts $facts): array
    {
        return JsTooling::startScriptFor($facts) !== null || JsTooling::safeMain($facts->json('package.json')['main'] ?? null) !== null || $facts->exists('server.js')
            ? []
            : [new Blocker('repository', 'There is no start script and no main file in package.json, so Coresmith has nothing to start.', 'Add a start script to package.json, for example "start": "node server.js".')];
    }
}
