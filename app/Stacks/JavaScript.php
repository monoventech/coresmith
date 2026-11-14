<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Stacks;

use App\Models\Application;
use App\Services\Deployment\ReleasePaths;
use App\Services\Runtime\AsdfManager;
use App\Services\Runtime\JsTooling;
use App\Services\Runtime\Stack;

final readonly class JavaScript implements Language
{
    public function __construct(private JsCommands $js, private AsdfManager $asdf) {}

    public function install(Application $application, Stack $stack): array
    {
        return $this->js->install($stack);
    }

    public function build(Application $application, Stack $stack): array
    {
        return $this->js->build($application, $stack);
    }

    public function migrations(Application $application, Stack $stack): array
    {
        return [];
    }

    public function releaseChecks(Application $application, ReleasePaths $paths, string $release): array
    {
        $base = $paths->appDirectory($release);

        if ($application->js_runtime === 'deno') {
            return ['deno.json, deno.jsonc or package.json' => implode(' || ', array_map(fn(string $file): string => 'test -f ' . escapeshellarg($base . '/' . $file), ['deno.json', 'deno.jsonc', 'package.json']))];
        }

        return ['package.json' => 'test -f ' . escapeshellarg($base . '/package.json')];
    }

    public function webCommand(Application $application): ?string
    {
        $data = ((array) $application->stack)['tooling'] ?? null;
        $tooling = JsTooling::fromArray(is_array($data) ? $data : null);
        $start = $tooling?->start ?? false;
        $script = $tooling?->startScript ?? 'start';

        return match ($application->js_runtime) {
            'node' => $start
                ? $this->asdf->binaryPath('nodejs', (string) $application->node_version, 'npm') . ' run ' . $script
                : $this->asdf->binaryPath('nodejs', (string) $application->node_version) . ' ' . $this->entry($application, $tooling),
            'deno' => $this->asdf->binaryPath('deno', (string) $application->deno_version) . ($start ? ' task start' : ' run --allow-net --allow-read --allow-env main.ts'),
            default => $this->asdf->binaryPath('bun', (string) $application->bun_version) . ($start ? ' run --bun ' . $script : ' ' . $this->entry($application, $tooling)),
        };
    }

    public function usesFpm(): bool
    {
        return false;
    }

    private function entry(Application $application, ?JsTooling $tooling): string
    {
        return match (strtolower((string) $application->framework)) {
            'sveltekit' => 'build/index.js',
            'nuxt' => '.output/server/index.mjs',
            'astro' => 'dist/server/entry.mjs',
            default => $tooling?->main ?? 'server.js',
        };
    }
}
