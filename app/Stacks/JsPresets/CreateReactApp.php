<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Stacks\JsPresets;

use App\Services\Runtime\RepositoryFacts;
use App\Stacks\JsFrameworks;
use App\Stacks\Proposal;

final class CreateReactApp extends JsPreset
{
    public function __construct(private readonly JsFrameworks $frameworks) {}

    public function key(): string
    {
        return 'react-scripts';
    }

    public function label(): string
    {
        return 'Create React App';
    }

    public function package(): string
    {
        return 'react-scripts';
    }

    public function claims(RepositoryFacts $facts, string $build): bool
    {
        return $facts->dependency('react-scripts') !== null && str_contains($build, 'react-scripts build');
    }

    public function propose(RepositoryFacts $facts, string $runtime, array $sources, ?string $output): array
    {
        return Proposal::staticBuild('/build', ['type' => 'From the react-scripts build script in package.json'], '');
    }
}
