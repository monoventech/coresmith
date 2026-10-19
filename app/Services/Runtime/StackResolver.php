<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Runtime;

use App\Enums\ApplicationType;
use App\Models\Application;
use Closure;

final class StackResolver
{
    public function __construct(
        private readonly RuntimeDetector $detector,
        private readonly RuntimeResolver $resolver,
        private readonly FrameworkVersion $frameworks,
    ) {}

    /**
     * @param  Closure(string): list<string>  $installed
     */
    public function resolve(Application $application, RepositoryFacts $facts, Closure $installed): Stack
    {
        $detection = $this->detector->detect($application, $facts);
        $pins = array_filter((array) $application->runtime_pins, 'is_string');
        $runtimes = [];
        $notes = $detection->notes;

        foreach ($detection->runtimes as $runtime) {
            $resolved = $this->resolver->resolve($runtime, $detection->requirements[$runtime] ?? null, $pins[$runtime] ?? null, $installed($runtime));

            if ($resolved->note !== null) {
                $notes[] = $resolved->note;
            }

            $runtimes[$runtime] = $resolved;
        }

        return new Stack($runtimes, $this->frameworks->detect($application, $facts), $detection->tooling, $notes, $this->gems($application, $facts));
    }

    /**
     * @return array<string, bool>
     */
    private function gems(Application $application, RepositoryFacts $facts): array
    {
        if ($application->type !== ApplicationType::Ruby) {
            return [];
        }

        $gems = [];

        foreach (Stack::GEMS as $gem) {
            $gems[$gem] = $facts->locked($gem) !== null;
        }

        return $gems;
    }
}
