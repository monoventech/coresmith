<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Stacks\JsPresets;

use App\Services\Runtime\RepositoryFacts;
use App\Stacks\Proposal;

final class AdonisJs extends JsPreset
{
    public function key(): string
    {
        return 'adonisjs';
    }

    public function label(): string
    {
        return 'AdonisJS';
    }

    public function package(): string
    {
        return '@adonisjs/core';
    }

    public function serves(): bool
    {
        return true;
    }

    public function claims(RepositoryFacts $facts, string $build): bool
    {
        return $facts->dependency('@adonisjs/core') !== null && $facts->exists('ace.js');
    }

    public function unbuilt(string $build): ?string
    {
        return str_contains($build, 'node ace build') ? null : 'node ace build';
    }

    public function propose(RepositoryFacts $facts, string $runtime, array $sources, ?string $output): array
    {
        return Proposal::make('js', 'adonisjs', $runtime, null, [...$sources, 'framework' => '@adonisjs/core in package.json, with ace.js']);
    }

    public function entry(): ?string
    {
        return 'build/bin/server.js';
    }

    public function startsFromEntry(): bool
    {
        return true;
    }

    public function environment(): array
    {
        return ['LOG_LEVEL' => 'info', 'SESSION_DRIVER' => 'cookie'];
    }

    public function initialEnvironment(): array
    {
        return ['APP_KEY' => bin2hex(random_bytes(16))];
    }
}
