<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Stacks;

use App\Models\Application;
use App\Services\Deployment\ReleasePaths;
use App\Services\Detection\Blocker;
use App\Services\Runtime\NativeTools;
use App\Services\Runtime\RepositoryFacts;
use App\Services\Runtime\ResolvedRuntime;
use App\Services\Runtime\Stack;

interface Language
{
    /**
     * @return list<string>
     */
    public function install(Application $application, Stack $stack): array;

    /**
     * @return list<string>
     */
    public function build(Application $application, Stack $stack): array;

    /**
     * @return list<string>
     */
    public function migrations(Application $application, Stack $stack): array;

    /**
     * @return array<string, string>
     */
    public function releaseChecks(Application $application, ReleasePaths $paths, string $release): array;

    public function webCommand(Application $application): ?string;

    public function usesFpm(): bool;

    public function route(Application $application): Route;

    public function storageSeed(): StorageSeed;

    /**
     * @return array<string, string>
     */
    public function initialEnvironment(?string $framework): array;

    /**
     * @return array<string, string>
     */
    public function processEnvironment(Application $application): array;

    /**
     * @return array<string, string>
     */
    public function listenEnvironment(Application $application): array;

    /**
     * @return array{type: ?string, framework: ?string, js_runtime: ?string, web: ?string, sources: array<string, string>, unsettled: array<string, string>}|null
     */
    public function detect(RepositoryFacts $facts): ?array;

    /**
     * @return list<Blocker>
     */
    public function checks(Application $choice, string $framework, RepositoryFacts $facts, ?string $arch): array;

    public function versionDoubt(RepositoryFacts $facts, ?ResolvedRuntime $runtime): ?string;

    public function cronExample(Application $application): string;

    /**
     * @return array<string, mixed>
     */
    public function tooling(Application $application, RepositoryFacts $facts): array;

    /**
     * @return list<string>
     */
    public function pathDirectories(string $appDirectory): array;

    public function nativeTools(): ?NativeTools;

    public function frameworkLabel(string $framework): ?string;
}
