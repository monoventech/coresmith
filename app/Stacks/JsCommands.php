<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Stacks;

use App\Models\Application;
use App\Services\Panel\SmartToggles;
use App\Services\Runtime\Stack;

final readonly class JsCommands
{
    public function __construct(private SmartToggles $toggles) {}

    /**
     * @return list<string>
     */
    public function install(Stack $stack): array
    {
        return $stack->tooling?->install() ?? [];
    }

    /**
     * @return list<string>
     */
    public function build(Application $application, Stack $stack): array
    {
        return $stack->tooling?->buildCommands($this->toggles->ssrBuilds($application, $stack)) ?? [];
    }
}
