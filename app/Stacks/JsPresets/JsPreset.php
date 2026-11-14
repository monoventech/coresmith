<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Stacks\JsPresets;

use App\Services\Detection\Blocker;
use App\Services\Runtime\RepositoryFacts;

abstract class JsPreset
{
    abstract public function key(): string;

    abstract public function label(): string;

    abstract public function package(): string;

    abstract public function claims(RepositoryFacts $facts, string $build): bool;

    /**
     * @param  array<string, string>  $sources
     * @return array{type: ?string, framework: ?string, js_runtime: ?string, web: ?string, sources: array<string, string>, unsettled: array<string, string>}
     */
    abstract public function propose(RepositoryFacts $facts, string $runtime, array $sources, ?string $output): array;

    public function serves(): bool
    {
        return false;
    }

    /**
     * @return list<string>
     */
    public function aliases(): array
    {
        return [$this->key()];
    }

    public function unbuilt(string $build): ?string
    {
        return null;
    }

    /**
     * @return list<Blocker>
     */
    public function blockers(RepositoryFacts $facts): array
    {
        return [];
    }

    public function entry(): ?string
    {
        return null;
    }

    /**
     * @return array<string, string>
     */
    public function environment(): array
    {
        return [];
    }

    public function startScript(RepositoryFacts $facts): ?string
    {
        return null;
    }

    public function ownsStart(): bool
    {
        return false;
    }

    public function main(RepositoryFacts $facts): ?string
    {
        return null;
    }

    /**
     * @return array<string, string>
     */
    public function initialEnvironment(): array
    {
        return [];
    }

    public function rulesOutput(): bool
    {
        return false;
    }

    public function staticOutput(RepositoryFacts $facts): ?string
    {
        return null;
    }
}
