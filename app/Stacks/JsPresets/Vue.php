<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Stacks\JsPresets;

use App\Services\Runtime\RepositoryFacts;

final class Vue extends FilesPreset
{
    public function key(): string
    {
        return 'vue';
    }

    public function label(): string
    {
        return 'Vue';
    }

    public function package(): string
    {
        return 'vue';
    }

    protected function bundler(RepositoryFacts $facts, string $build): ?string
    {
        return match (true) {
            $facts->dependency('@vue/cli-service') !== null && str_contains($build, 'vue-cli-service build') => 'vue-cli-service',
            $this->frameworks->viteBuild($facts, $build) => 'vite',
            default => null,
        };
    }

    protected function output(RepositoryFacts $facts, string $bundler): ?string
    {
        if ($bundler === 'vue-cli-service') {
            return $this->frameworks->config($facts, ['vue.config.js'], '~outputDir\s*:~') ? null : '/dist';
        }

        return $this->frameworks->viteOutput($facts);
    }

    protected function unknownOutput(string $bundler): string
    {
        return $bundler === 'vue-cli-service'
            ? "vue.config.js sets its own outputDir, so Coresmith can't be sure where the build goes. Set the web root to that folder."
            : parent::unknownOutput($bundler);
    }
}
