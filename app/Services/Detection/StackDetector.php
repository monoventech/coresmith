<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Detection;

use App\Enums\ApplicationType;
use App\Exceptions\RuntimeUnavailable;
use App\Models\Application;
use App\Services\Runtime\Catalog;
use App\Services\Runtime\RepositoryFacts;
use App\Services\Runtime\Stack;
use App\Services\Runtime\StackResolver;
use App\Stacks\Proposal;
use Closure;

final class StackDetector
{
    public const UNFINISHED = "Coresmith couldn't read all of the repository's files, so it can't tell what the app runs on.";

    public const NOTHING = "There is no composer.json, Gemfile, package.json, deno.json or index.html at the repository's root, so Coresmith can't tell what the app runs on.";

    private const ORDER = [ApplicationType::Php, ApplicationType::Ruby, ApplicationType::Js, ApplicationType::Static];

    public function __construct(
        private readonly StackChecks $checks,
        private readonly StackResolver $resolver,
        private readonly Catalog $catalog,
    ) {}

    /**
     * @param  Closure(string): list<string>  $installed
     */
    public function detect(RepositoryFacts $facts, Closure $installed, ?Closure $extensions = null, ?string $arch = null): DetectedStack
    {
        if (!$facts->complete) {
            return new DetectedStack(null, null, null, null, unsettled: ['type' => self::UNFINISHED]);
        }

        $proposal = null;

        foreach (self::ORDER as $candidate) {
            $proposal = $candidate->language()->detect($facts);

            if ($proposal !== null) {
                break;
            }
        }

        $proposal ??= Proposal::unsure('type', self::NOTHING);

        if ($proposal['type'] === null) {
            return new DetectedStack(null, null, null, null, $proposal['sources'], $proposal['unsettled']);
        }

        $type = ApplicationType::from($proposal['type']);
        $application = new Application([
            'type' => $type,
            'framework' => $proposal['framework'] === '' ? null : $proposal['framework'],
            'js_runtime' => $proposal['js_runtime'],
            'web_directory' => $proposal['web'] ?? '/public',
            'directory' => '/',
        ]);

        $stack = $this->stack($application, $facts, $installed);
        $runtime = $this->runtimeOf($type, $proposal['js_runtime'], $stack);
        $sources = $proposal['sources'];
        $unsettled = $proposal['unsettled'];

        if ($runtime !== null && isset($stack?->runtimes[$runtime])) {
            $sources['runtime_version'] = $stack->runtimes[$runtime]->describe($this->catalog->label($runtime));
        }

        $doubt = $type->language()->versionDoubt($facts, $runtime === null ? null : ($stack?->runtimes[$runtime] ?? null));

        if ($doubt !== null) {
            $unsettled['runtime_version'] = $doubt;
        }

        $node = $stack?->runtimes['nodejs'] ?? null;

        if ($node !== null && $facts->dependency('node-sass') !== null && version_compare($node->version, '21', '>=')) {
            $unsettled['runtime_version'] ??= "node-sass builds on Node.js 20 at most, and Coresmith would use Node.js {$node->version}. Replace node-sass with sass, or name a Node.js version your node-sass supports in .nvmrc.";
        }

        return new DetectedStack(
            $proposal['type'],
            $proposal['framework'],
            $proposal['js_runtime'],
            $proposal['web'],
            $sources,
            $unsettled,
            isset($unsettled['framework']) ? [] : $this->checks->blockers($application, $facts, $installed, extensions: $extensions, arch: $arch),
            $stack,
            $runtime,
        );
    }

    /**
     * @param  Closure(string): list<string>  $installed
     */
    private function stack(Application $application, RepositoryFacts $facts, Closure $installed): ?Stack
    {
        try {
            return $this->resolver->resolve($application, $facts, $installed);
        } catch (RuntimeUnavailable) {
            return null;
        }
    }

    private function runtimeOf(ApplicationType $type, ?string $jsRuntime, ?Stack $stack): ?string
    {
        return $type->runtime($jsRuntime) ?? $stack?->tooling?->runtime();
    }
}
