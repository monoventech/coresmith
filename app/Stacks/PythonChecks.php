<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Stacks;

use App\Services\Detection\Blocker;
use App\Services\Runtime\RepositoryFacts;
use App\Stacks\PythonPresets\Django;
use App\Stacks\PythonPresets\PythonPresets;

final readonly class PythonChecks
{
    public function __construct(private PythonPresets $presets, private PythonDetection $detection) {}

    /**
     * @return list<Blocker>
     */
    public function checks(string $framework, RepositoryFacts $facts): array
    {
        $dependencies = PythonDependencies::from($facts);
        $preset = $this->presets->find($framework);
        $list = PythonDependencies::list($facts);

        if ($preset !== null && !$preset->claims($dependencies)) {
            return [$list === null
                ? new Blocker('repository', "There is no requirements.txt, pyproject.toml or Pipfile {$facts->at()}, so Coresmith doesn't know what to install.", 'Commit the file that lists the app\'s packages, for example the requirements.txt that pip freeze writes.')
                : new Blocker('framework', "{$preset->package()} isn't in {$list}, so this isn't a {$preset->label()} app.", "Pick the framework the app uses, or add {$preset->package()} to {$list}.")];
        }

        $blockers = [];

        if (PythonTooling::managerFor($facts) === 'pipenv' && !$facts->exists('Pipfile.lock')) {
            $blockers[] = new Blocker('repository', 'There is no Pipfile.lock, and Coresmith installs Pipenv apps from their lockfile.', 'Run pipenv lock, then commit Pipfile.lock.');
        }

        $app = $this->detection->app($framework, $facts);

        if ($app->problem !== null) {
            $blockers[] = new Blocker('framework', $app->problem[0], $app->problem[1]);
        }

        $hosts = $preset instanceof Django ? $preset->emptyAllowedHosts($facts) : null;

        if ($hosts !== null) {
            $blockers[] = new Blocker('repository', $hosts[0], $hosts[1]);
        }

        return $blockers;
    }
}
