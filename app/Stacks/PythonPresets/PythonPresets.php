<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Stacks\PythonPresets;

use App\Stacks\PythonDependencies;

final class PythonPresets
{
    /**
     * @return list<PythonPreset>
     */
    public function all(): array
    {
        return [app(Django::class), app(FastApi::class), app(Starlette::class), app(Flask::class)];
    }

    public function find(string $framework): ?PythonPreset
    {
        foreach ($this->all() as $preset) {
            if ($preset->key() === strtolower($framework)) {
                return $preset;
            }
        }

        return null;
    }

    public function claiming(PythonDependencies $dependencies): ?PythonPreset
    {
        foreach ($this->all() as $preset) {
            if ($preset->claims($dependencies)) {
                return $preset;
            }
        }

        return null;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public function choices(): array
    {
        return array_map(fn(PythonPreset $preset): array => ['value' => $preset->key(), 'label' => $preset->label()], $this->all());
    }
}
