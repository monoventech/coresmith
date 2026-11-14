<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Stacks\JsPresets;

use App\Services\Runtime\RepositoryFacts;
use App\Stacks\JsFrameworks;
use App\Stacks\Proposal;

final class Vite extends JsPreset
{
    public function __construct(private readonly JsFrameworks $frameworks) {}

    public function key(): string
    {
        return 'vite';
    }

    public function label(): string
    {
        return 'Vite';
    }

    public function package(): string
    {
        return 'vite';
    }

    public function claims(RepositoryFacts $facts, string $build): bool
    {
        return $facts->dependency('vite') !== null && str_contains($build, 'vite build') && $this->viteOnly($facts);
    }

    public function propose(RepositoryFacts $facts, string $runtime, array $sources, ?string $output): array
    {
        return Proposal::staticBuild($output, ['type' => 'From the vite build script in package.json'], 'vite.config sets its own outDir, so Coresmith can\'t be sure where the build goes. Set the web root to that folder.');
    }

    public function rulesOutput(): bool
    {
        return true;
    }

    public function staticOutput(RepositoryFacts $facts): ?string
    {
        return $this->frameworks->config($facts, ['vite.config.ts', 'vite.config.js'], '~outDir\s*:~') ? null : '/dist';
    }

    private function viteOnly(RepositoryFacts $facts): bool
    {
        $start = (string) ($facts->json('package.json')['scripts']['start'] ?? '');

        return trim($start) === '' || preg_match('~^\s*vite(\s|$)~', $start) === 1;
    }
}
