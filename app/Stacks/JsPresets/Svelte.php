<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Stacks\JsPresets;

use App\Services\Runtime\RepositoryFacts;

final class Svelte extends FilesPreset
{
    public function key(): string
    {
        return 'svelte';
    }

    public function label(): string
    {
        return 'Svelte';
    }

    public function package(): string
    {
        return 'svelte';
    }

    protected function bundler(RepositoryFacts $facts, string $build): ?string
    {
        return match (true) {
            $facts->dependency('rollup') !== null && preg_match('~(^|[\s;&|(])rollup\s+(-c|--config)(\s|$)~', $build) === 1 && $facts->exists('public/index.html') => 'rollup',
            $this->frameworks->viteBuild($facts, $build) => 'vite',
            default => null,
        };
    }

    protected function output(RepositoryFacts $facts, string $bundler): ?string
    {
        return $bundler === 'rollup' ? '/public' : $this->frameworks->viteOutput($facts);
    }
}
