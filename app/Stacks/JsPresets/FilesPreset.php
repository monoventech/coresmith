<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Stacks\JsPresets;

use App\Services\Runtime\RepositoryFacts;
use App\Stacks\JsFrameworks;
use App\Stacks\Proposal;

abstract class FilesPreset extends JsPreset
{
    public function __construct(protected readonly JsFrameworks $frameworks) {}

    abstract protected function bundler(RepositoryFacts $facts, string $build): ?string;

    abstract protected function output(RepositoryFacts $facts, string $bundler): ?string;

    public function serves(): bool
    {
        return true;
    }

    public function servesFiles(): bool
    {
        return true;
    }

    public function claims(RepositoryFacts $facts, string $build): bool
    {
        return $facts->dependency($this->package()) !== null && $this->bundler($facts, $build) !== null;
    }

    public function propose(RepositoryFacts $facts, string $runtime, array $sources, ?string $output): array
    {
        $bundler = (string) $this->bundler($facts, $this->build($facts));
        $web = $this->output($facts, $bundler);
        $sources = [...$sources, 'framework' => "{$this->package()} in package.json, built with {$bundler}"];

        return $web === null
            ? Proposal::make('js', $this->key(), $runtime, null, $sources, ['web_directory' => $this->unknownOutput($bundler)])
            : Proposal::make('js', $this->key(), $runtime, $web, [...$sources, 'web_directory' => 'The build writes to ' . ltrim($web, '/')]);
    }

    public function staticOutput(RepositoryFacts $facts): ?string
    {
        $bundler = $this->bundler($facts, $this->build($facts));

        return $bundler === null ? null : $this->output($facts, $bundler);
    }

    protected function unknownOutput(string $bundler): string
    {
        return "vite.config sets its own outDir, so Coresmith can't be sure where the build goes. Set the web root to that folder.";
    }

    private function build(RepositoryFacts $facts): string
    {
        return (string) ($facts->json('package.json')['scripts']['build'] ?? '');
    }
}
