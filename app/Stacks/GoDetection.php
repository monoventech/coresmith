<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Stacks;

use App\Services\Runtime\RepositoryFacts;

final readonly class GoDetection
{
    /**
     * @return array<string, mixed>|null
     */
    public function detect(RepositoryFacts $facts): ?array
    {
        $module = GoModule::from($facts);

        if ($module === null) {
            return null;
        }

        $source = GoSource::from($facts);
        $framework = GoFrameworks::claiming($module, $source);
        $mains = $source->mains();
        [$web, $unsettled] = $this->web($source, $mains, $facts);
        $sources = ['type' => 'go.mod', 'framework' => GoFrameworks::evidence($framework, $module)];

        if ($web !== null) {
            $sources['main_package'] = count($mains) === 1 ? 'the only package main' : 'the package main that starts a server';
        }

        return Proposal::make('go', $framework, null, null, $sources, $unsettled, ['main_package' => $web], ['main_package' => $mains]);
    }

    /**
     * @param  list<string>  $mains
     * @return array{0: ?string, 1: array<string, string>}
     */
    public function web(GoSource $source, array $mains, RepositoryFacts $facts): array
    {
        if ($mains === []) {
            return [null, ['main_package' => "Coresmith found no package main {$facts->at()} or two folders below it, so it can't tell what to run. Type the folder of the package that starts the app, such as cmd/server."]];
        }

        if (count($mains) === 1) {
            return [$mains[0], []];
        }

        $serving = array_values(array_filter($mains, fn(string $main): bool => $source->serves($main)));

        if (count($serving) === 1) {
            return [$serving[0], []];
        }

        $listed = implode(', ', array_map(fn(string $main): string => $main === '.' ? 'the module root' : $main, $serving === [] ? $mains : $serving));

        return [null, ['main_package' => $serving === []
            ? "Several packages declare package main ({$listed}) and none starts a server Coresmith recognises. Pick the one that answers web requests."
            : "Several packages start a server ({$listed}). Pick the one that answers web requests."]];
    }
}
