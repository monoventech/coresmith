<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Stacks\JsPresets;

use App\Services\Runtime\RepositoryFacts;

final readonly class JsPresets
{
    private const ORDER = [SvelteKit::class, NextJs::class, Nuxt::class, Astro::class, CreateReactApp::class, Vite::class];

    /**
     * @return list<JsPreset>
     */
    public function all(): array
    {
        return array_map(fn(string $class): JsPreset => app($class), self::ORDER);
    }

    public function find(string $framework): ?JsPreset
    {
        $framework = strtolower($framework);

        foreach ($this->all() as $preset) {
            if ($preset->serves() && in_array($framework, $preset->aliases(), true)) {
                return $preset;
            }
        }

        return null;
    }

    public function staticOutput(RepositoryFacts $facts): ?string
    {
        foreach ($this->all() as $preset) {
            if ($preset->rulesOutput() && $facts->dependency($preset->package()) !== null) {
                return $preset->staticOutput($facts);
            }
        }

        return null;
    }
}
