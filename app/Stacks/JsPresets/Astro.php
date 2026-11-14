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

final class Astro extends JsPreset
{
    public function __construct(private readonly JsFrameworks $frameworks) {}

    public function key(): string
    {
        return 'astro';
    }

    public function label(): string
    {
        return 'Astro';
    }

    public function package(): string
    {
        return 'astro';
    }

    public function serves(): bool
    {
        return true;
    }

    public function claims(RepositoryFacts $facts, string $build): bool
    {
        return $facts->dependency('astro') !== null;
    }

    public function unbuilt(string $build): ?string
    {
        return str_contains($build, 'astro build') ? null : 'astro build';
    }

    public function propose(RepositoryFacts $facts, string $runtime, array $sources, ?string $output): array
    {
        return $this->frameworks->astroServer($facts)
            ? Proposal::make('js', 'astro', $runtime, null, [...$sources, 'framework' => 'astro with @astrojs/node in package.json'])
            : Proposal::staticBuild($output, ['type' => 'From astro in package.json, with no server adapter'], 'astro.config sets its own outDir, so Coresmith can\'t be sure where the build goes. Set the web root to that folder.');
    }

    public function blockers(RepositoryFacts $facts): array
    {
        return match (true) {
            $facts->dependency('astro') === null => [new Blocker('framework', "astro isn't in package.json, so this isn't an Astro app.", 'Pick the framework the app uses, or API or other.')],
            !$this->frameworks->astroServer($facts) => [new Blocker('type', 'This Astro app has no @astrojs/node adapter, so the build is static files, not a server.', 'Pick Static site with the web root /dist, or add @astrojs/node to serve pages on demand.')],
            default => [],
        };
    }

    public function entry(): ?string
    {
        return 'dist/server/entry.mjs';
    }

    public function rulesOutput(): bool
    {
        return true;
    }

    public function staticOutput(RepositoryFacts $facts): ?string
    {
        return !$this->frameworks->astroServer($facts) && !$this->frameworks->config($facts, ['astro.config.mjs', 'astro.config.ts', 'astro.config.js'], '~outDir\s*:~') ? '/dist' : null;
    }
}
