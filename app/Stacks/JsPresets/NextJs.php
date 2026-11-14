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

final class NextJs extends JsPreset
{
    public function __construct(private readonly JsFrameworks $frameworks) {}

    public function key(): string
    {
        return 'nextjs';
    }

    public function label(): string
    {
        return 'Next.js';
    }

    public function package(): string
    {
        return 'next';
    }

    public function serves(): bool
    {
        return true;
    }

    public function aliases(): array
    {
        return ['nextjs', 'next'];
    }

    public function claims(RepositoryFacts $facts, string $build): bool
    {
        return $facts->dependency('next') !== null;
    }

    public function unbuilt(string $build): ?string
    {
        return str_contains($build, 'next build') ? null : 'next build';
    }

    public function propose(RepositoryFacts $facts, string $runtime, array $sources, ?string $output): array
    {
        return $this->frameworks->nextExport($facts)
            ? Proposal::staticBuild($output, ['type' => "From output: 'export' in next.config"], 'next.config sets its own distDir, so Coresmith can\'t be sure where the export goes. Set the web root to that folder.')
            : Proposal::make('js', 'nextjs', $runtime, null, [...$sources, 'framework' => 'next in package.json']);
    }

    public function blockers(RepositoryFacts $facts): array
    {
        return match (true) {
            $facts->dependency('next') === null => [new Blocker('framework', "next isn't in package.json, so this isn't a Next.js app.", 'Pick the framework the app uses, or API or other.')],
            $this->frameworks->nextExport($facts) => [new Blocker('type', "next.config sets output: 'export', so the build is static files, not a server.", 'Pick Static site with the web root /out.')],
            !$facts->script('start') => [new Blocker('repository', 'There is no start script in package.json, so there is nothing to start the Next.js server with.', 'Add "start": "next start" to the scripts in package.json.')],
            default => [],
        };
    }

    public function rulesOutput(): bool
    {
        return true;
    }

    public function staticOutput(RepositoryFacts $facts): ?string
    {
        return $this->frameworks->nextExport($facts) && !$this->frameworks->config($facts, ['next.config.js', 'next.config.mjs', 'next.config.ts'], '~distDir\s*:~') ? '/out' : null;
    }
}
