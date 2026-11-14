<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Stacks\JsPresets;

use App\Services\Detection\Blocker;
use App\Services\Runtime\RepositoryFacts;
use App\Stacks\JsFrameworks;
use App\Stacks\Proposal;

final class Nuxt extends JsPreset
{
    public function __construct(private readonly JsFrameworks $frameworks) {}

    public function key(): string
    {
        return 'nuxt';
    }

    public function label(): string
    {
        return 'Nuxt';
    }

    public function package(): string
    {
        return 'nuxt';
    }

    public function serves(): bool
    {
        return true;
    }

    public function claims(RepositoryFacts $facts, string $build): bool
    {
        return $facts->dependency('nuxt') !== null;
    }

    public function unbuilt(string $build): ?string
    {
        return preg_match('/\bnux[ti] (build|generate)\b/', $build) === 1 ? null : 'nuxt build';
    }

    public function propose(RepositoryFacts $facts, string $runtime, array $sources, ?string $output): array
    {
        $preset = $this->frameworks->nitroPreset($facts);

        return $this->frameworks->nuxtGenerate($facts)
            ? Proposal::staticBuild($output, ['type' => 'From the nuxt generate build script'], '')
            : Proposal::make('js', 'nuxt', $runtime, null, [...$sources, 'framework' => 'nuxt in package.json'], match (true) {
                $preset !== null && !$preset[1] => ['framework' => "nuxt.config can set Nitro's preset to {$preset[0]}, which makes the app for that host, not the Node server Coresmith runs. Set the preset to node-server."],
                $facts->dependency('@nuxthub/core') !== null => ['framework' => '@nuxthub/core builds the app for Cloudflare, not the Node server Coresmith runs.'],
                default => [],
            });
    }

    public function blockers(RepositoryFacts $facts): array
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

    public function entry(): ?string
    {
        return '.output/server/index.mjs';
    }

    public function rulesOutput(): bool
    {
        return true;
    }

    public function staticOutput(RepositoryFacts $facts): ?string
    {
        return $this->frameworks->nuxtGenerate($facts) ? '/.output/public' : null;
    }
}
