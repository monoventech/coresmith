<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Stacks\JsPresets;

use App\Services\Runtime\RepositoryFacts;

final class React extends FilesPreset
{
    public function key(): string
    {
        return 'react';
    }

    public function label(): string
    {
        return 'React';
    }

    public function package(): string
    {
        return 'react';
    }

    protected function bundler(RepositoryFacts $facts, string $build): ?string
    {
        return match (true) {
            $facts->dependency('react-scripts') !== null && str_contains($build, 'react-scripts build') => 'react-scripts',
            $this->frameworks->viteBuild($facts, $build) => 'vite',
            default => null,
        };
    }

    protected function output(RepositoryFacts $facts, string $bundler): ?string
    {
        return $bundler === 'react-scripts' ? '/build' : $this->frameworks->viteOutput($facts);
    }
}
