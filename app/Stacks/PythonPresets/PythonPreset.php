<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Stacks\PythonPresets;

use App\Services\Runtime\RepositoryFacts;
use App\Stacks\PythonApp;
use App\Stacks\PythonDependencies;

abstract class PythonPreset
{
    abstract public function key(): string;

    abstract public function label(): string;

    abstract public function package(): string;

    abstract public function app(RepositoryFacts $facts, PythonDependencies $dependencies): PythonApp;

    public function claims(PythonDependencies $dependencies): bool
    {
        return $dependencies->has($this->package());
    }

    public function evidence(RepositoryFacts $facts, PythonDependencies $dependencies): string
    {
        $pinned = $dependencies->pinned($this->package());

        return $this->package() . ($pinned === null ? '' : ' ' . $pinned) . ' in ' . $dependencies->file($this->package());
    }

    /**
     * @return array<string, string>
     */
    public function initialEnvironment(): array
    {
        return [];
    }

    protected function alembic(RepositoryFacts $facts, PythonDependencies $dependencies): ?string
    {
        return $facts->exists('alembic.ini') && $dependencies->has('alembic') ? 'alembic' : null;
    }
}
