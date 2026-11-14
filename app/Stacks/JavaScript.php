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
use App\Services\Runtime\RepositoryFacts;
use App\Services\Runtime\ResolvedRuntime;
use App\Services\Runtime\Stack;
use App\Stacks\JsPresets\JsPresets;

final readonly class JavaScript implements Language
{
    public function __construct(private JsCommands $js, private AsdfManager $asdf, private JavaScriptDetection $detection, private JavaScriptChecks $checks, private JsPresets $presets) {}

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

    public function route(Application $application): Route
    {
        return Route::ReverseProxy;
    }

    public function storageSeed(): StorageSeed
    {
        return StorageSeed::None;
    }

    public function initialEnvironment(?string $framework): array
    {
        return $this->presets->find((string) $framework)?->initialEnvironment() ?? [];
    }

    public function processEnvironment(Application $application): array
    {
        return [
            'NODE_ENV' => 'production',
            ...($this->presets->find((string) $application->framework)?->environment() ?? []),
        ];
    }

    public function listenEnvironment(Application $application): array
    {
        return ['PORT' => (string) $application->base_port, 'HOST' => '127.0.0.1', 'HOSTNAME' => '127.0.0.1'];
    }

    public function detect(RepositoryFacts $facts): ?array
    {
        return $this->detection->detect($facts);
    }

    public function checks(Application $choice, string $framework, RepositoryFacts $facts, ?string $arch): array
    {
        return $this->checks->checks($choice, $framework, $facts, $arch);
    }

    public function versionDoubt(RepositoryFacts $facts, ?ResolvedRuntime $runtime): ?string
    {
        return null;
    }

    public function cronExample(Application $application): string
    {
        return match ($application->js_runtime) {
            'node' => 'npm run cleanup',
            'deno' => 'deno task cleanup',
            default => 'bun run cleanup',
        };
    }

    private function entry(Application $application, ?JsTooling $tooling): string
    {
        return $this->presets->find((string) $application->framework)?->entry() ?? $tooling?->main ?? 'server.js';
    }
}
