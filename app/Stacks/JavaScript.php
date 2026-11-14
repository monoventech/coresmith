<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Stacks;

use App\Models\Application;
use App\Services\Deployment\ReleasePaths;
use App\Services\Runtime\Stack;

final readonly class JavaScript implements Language
{
    public function __construct(private JsCommands $js) {}

    public function install(Application $application, Stack $stack): array
    {
        return $this->js->install($stack);
    }

    public function build(Application $application, Stack $stack): array
    {
        return $this->js->build($application, $stack);
    }

    public function migrations(Application $application, Stack $stack): array
    {
        return [];
    }

    public function releaseChecks(Application $application, ReleasePaths $paths, string $release): array
    {
        $base = $paths->appDirectory($release);

        if ($application->js_runtime === 'deno') {
            return ['deno.json, deno.jsonc or package.json' => implode(' || ', array_map(fn(string $file): string => 'test -f ' . escapeshellarg($base . '/' . $file), ['deno.json', 'deno.jsonc', 'package.json']))];
        }

        return ['package.json' => 'test -f ' . escapeshellarg($base . '/package.json')];
    }
}
