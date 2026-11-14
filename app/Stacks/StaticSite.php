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
use App\Stacks\JsPresets\JsPresets;

final readonly class StaticSite implements Language
{
    private const STATIC_INDEXES = ['' => 'index.html', 'public' => 'public/index.html'];

    public function __construct(private JsCommands $js, private JsPresets $presets) {}

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
        $web = trim((string) $application->web_directory, '/');

        return [($web === '' ? 'the release root' : $web . '/') => 'test -d ' . escapeshellarg($paths->webRoot($release))];
    }

    public function webCommand(Application $application): ?string
    {
        return null;
    }

    public function usesFpm(): bool
    {
        return false;
    }

    public function route(Application $application): Route
    {
        return Route::FileServer;
    }

    public function storageSeed(): StorageSeed
    {
        return StorageSeed::None;
    }

    public function initialEnvironment(?string $framework): array
    {
        return [];
    }

    public function processEnvironment(Application $application): array
    {
        return [];
    }

    public function listenEnvironment(Application $application): array
    {
        return [];
    }

    /**
     * @return array{type: ?string, framework: ?string, js_runtime: ?string, web: ?string, sources: array<string, string>, unsettled: array<string, string>}|null
     */
    public function detect(RepositoryFacts $facts): ?array
    {
        foreach (['index.html' => '/', 'public/index.html' => '/public'] as $file => $web) {
            if ($facts->exists($file)) {
                return Proposal::make('static', '', null, $web, ['type' => "From {$file}", 'web_directory' => $web === '/' ? "index.html is {$facts->at()}" : 'index.html is in /public']);
            }
        }

        return null;
    }

    /**
     * @return list<Blocker>
     */
    public function checks(Application $choice, string $framework, RepositoryFacts $facts, ?string $arch): array
    {
        $web = trim((string) $choice->web_directory, '/');

        if ($facts->script('build')) {
            $output = $this->presets->staticOutput($facts);

            return $output !== null && $output !== '/' . $web
                ? [new Blocker('web_directory', "The build writes to {$output}, so the web root /{$web} would be empty.", "Set the web root to {$output}.")]
                : [];
        }

        $index = self::STATIC_INDEXES[$web] ?? null;

        if ($index === null || $facts->exists($index)) {
            return [];
        }

        $found = array_search(true, array_map(fn(string $path): bool => $facts->exists($path), self::STATIC_INDEXES), true);

        return [new Blocker(
            'web_directory',
            "There is no {$index}, so the web root /{$web} would serve nothing.",
            is_string($found) ? 'Set the web root to /' . $found . ', where index.html is.' : 'Set the web root to the folder that holds index.html.',
        )];
    }

    public function versionDoubt(RepositoryFacts $facts, ?ResolvedRuntime $runtime): ?string
    {
        return null;
    }

    public function cronExample(Application $application): string
    {
        return 'sh scripts/nightly.sh';
    }

    public function tooling(Application $application, RepositoryFacts $facts): array
    {
        return [];
    }

    public function pathDirectories(string $appDirectory): array
    {
        return [];
    }

    public function nativeTools(): ?NativeTools
    {
        return null;
    }

    public function frameworkLabel(string $framework): ?string
    {
        return null;
    }
}
