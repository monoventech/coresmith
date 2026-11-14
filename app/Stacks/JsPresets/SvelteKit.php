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

final class SvelteKit extends JsPreset
{
    public function __construct(private readonly JsFrameworks $frameworks) {}

    public function key(): string
    {
        return 'sveltekit';
    }

    public function label(): string
    {
        return 'SvelteKit';
    }

    public function package(): string
    {
        return '@sveltejs/kit';
    }

    public function serves(): bool
    {
        return true;
    }

    public function claims(RepositoryFacts $facts, string $build): bool
    {
        return $facts->dependency('@sveltejs/kit') !== null;
    }

    public function unbuilt(string $build): ?string
    {
        return str_contains($build, 'vite build') ? null : 'vite build';
    }

    public function propose(RepositoryFacts $facts, string $runtime, array $sources, ?string $output): array
    {
        $adapter = $this->frameworks->svelteAdapter($facts);

        if ($adapter === '@sveltejs/adapter-static') {
            return Proposal::staticBuild($output, ['type' => 'From @sveltejs/adapter-static in svelte.config.js'], 'Coresmith couldn\'t tell where adapter-static writes, because svelte.config.js sets its own pages folder. Set the web root to that folder.');
        }

        return Proposal::make('js', 'sveltekit', $runtime, null, [...$sources, 'framework' => $adapter === null ? '@sveltejs/kit in package.json' : "@sveltejs/kit with {$adapter}"], $adapter === null ? ['framework' => "Coresmith couldn't tell which adapter svelte.config.js uses. SvelteKit needs @sveltejs/adapter-node to run on a server."] : []);
    }

    public function blockers(RepositoryFacts $facts): array
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

    public function entry(): ?string
    {
        return 'build/index.js';
    }

    public function environment(): array
    {
        return ['PROTOCOL_HEADER' => 'x-forwarded-proto', 'HOST_HEADER' => 'x-forwarded-host', 'ADDRESS_HEADER' => 'x-forwarded-for'];
    }

    public function rulesOutput(): bool
    {
        return true;
    }

    public function staticOutput(RepositoryFacts $facts): ?string
    {
        return $this->frameworks->svelteAdapter($facts) === '@sveltejs/adapter-static' && !$this->frameworks->config($facts, ['svelte.config.js', 'svelte.config.ts'], '~pages\s*:~') ? '/build' : null;
    }
}
