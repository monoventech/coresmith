<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Stacks\JsPresets;

use App\Services\Detection\Blocker;
use App\Services\Runtime\JsTooling;
use App\Services\Runtime\RepositoryFacts;
use App\Stacks\Proposal;
use Illuminate\Support\Arr;

final class Angular extends JsPreset
{
    public function key(): string
    {
        return 'angular';
    }

    public function label(): string
    {
        return 'Angular';
    }

    public function package(): string
    {
        return '@angular/core';
    }

    public function serves(): bool
    {
        return true;
    }

    public function claims(RepositoryFacts $facts, string $build): bool
    {
        return $facts->dependency('@angular/core') !== null && $facts->has('angular.json');
    }

    public function unbuilt(string $build): ?string
    {
        return str_contains($build, 'ng build') ? null : 'ng build';
    }

    public function propose(RepositoryFacts $facts, string $runtime, array $sources, ?string $output): array
    {
        $applications = $this->applications($facts);

        if (count($applications) !== 1) {
            return Proposal::make('js', null, $runtime, null, $sources, ['framework' => $applications === []
                ? "angular.json has no application, so Coresmith can't tell what to deploy."
                : 'angular.json has more than one application (' . Arr::join(array_keys($applications), ', ', ' and ') . "), so Coresmith can't tell which one to deploy."]);
        }

        $name = (string) array_key_first($applications);

        if ($this->rendersOnServer($facts, $applications[$name])) {
            return Proposal::make('js', 'angular', $runtime, null, [...$sources, 'framework' => "@angular/ssr in package.json, serving {$name}"]);
        }

        $web = $this->browserOutput($facts, $name, $applications[$name]);

        return Proposal::staticBuild($web, ['type' => "From angular.json, where {$name} builds to " . ltrim($web, '/')], '');
    }

    public function blockers(RepositoryFacts $facts): array
    {
        $name = $this->serverName($facts);

        if ($name === null || $this->startScript($facts) !== null || $this->main($facts) !== null) {
            return [];
        }

        return [new Blocker('repository', "angular.json doesn't say plainly where {$name}'s server bundle goes, so Coresmith can't start it.", "Add \"serve:ssr:{$name}\": \"node <the path to server.mjs>\" to the scripts in package.json.")];
    }

    public function startScript(RepositoryFacts $facts): ?string
    {
        $name = $this->serverName($facts);

        return $name !== null && $facts->script("serve:ssr:{$name}") ? "serve:ssr:{$name}" : null;
    }

    public function ownsStart(): bool
    {
        return true;
    }

    public function main(RepositoryFacts $facts): ?string
    {
        $name = $this->serverName($facts);

        if ($name === null) {
            return null;
        }

        $outputPath = $this->buildOptions($facts, $this->applications($facts)[$name])['outputPath'] ?? "dist/{$name}";
        $base = trim(is_array($outputPath) ? (string) ($outputPath['base'] ?? "dist/{$name}") : (string) $outputPath, '/');
        $server = is_array($outputPath) && array_key_exists('server', $outputPath) ? trim((string) $outputPath['server'], '/') : 'server';

        return JsTooling::safeMain($server === '' ? "{$base}/server.mjs" : "{$base}/{$server}/server.mjs");
    }

    public function rulesOutput(): bool
    {
        return true;
    }

    public function staticOutput(RepositoryFacts $facts): ?string
    {
        $applications = $this->applications($facts);
        $name = count($applications) === 1 ? (string) array_key_first($applications) : null;

        return $name === null || $this->rendersOnServer($facts, $applications[$name]) ? null : $this->browserOutput($facts, $name, $applications[$name]);
    }

    private function serverName(RepositoryFacts $facts): ?string
    {
        $applications = $this->applications($facts);
        $name = count($applications) === 1 ? (string) array_key_first($applications) : null;

        return $name !== null && $this->rendersOnServer($facts, $applications[$name]) ? $name : null;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function applications(RepositoryFacts $facts): array
    {
        $projects = $facts->json('angular.json')['projects'] ?? [];

        return is_array($projects)
            ? array_filter($projects, fn(mixed $project, int|string $name): bool => is_string($name) && preg_match(JsTooling::SERVER_SCRIPT, "serve:ssr:{$name}") === 1 && is_array($project) && ($project['projectType'] ?? 'application') === 'application', ARRAY_FILTER_USE_BOTH)
            : [];
    }

    /**
     * @param  array<string, mixed>  $project
     */
    private function rendersOnServer(RepositoryFacts $facts, array $project): bool
    {
        $ssr = $this->buildOptions($facts, $project)['ssr'] ?? false;

        return $facts->dependency('@angular/ssr') !== null && $ssr !== false && $ssr !== null;
    }

    /**
     * @param  array<string, mixed>  $project
     */
    private function browserOutput(RepositoryFacts $facts, string $name, array $project): string
    {
        $build = (array) ($project['architect']['build'] ?? []);
        $outputPath = $this->buildOptions($facts, $project)['outputPath'] ?? "dist/{$name}";
        $base = trim(is_array($outputPath) ? (string) ($outputPath['base'] ?? "dist/{$name}") : (string) $outputPath, '/');
        $browser = is_array($outputPath) && array_key_exists('browser', $outputPath) ? trim((string) $outputPath['browser'], '/') : 'browser';
        $application = str_ends_with((string) ($build['builder'] ?? ''), ':application');

        return '/' . ($application && $browser !== '' ? "{$base}/{$browser}" : $base);
    }

    /**
     * @param  array<string, mixed>  $project
     * @return array<string, mixed>
     */
    private function buildOptions(RepositoryFacts $facts, array $project): array
    {
        $build = (array) ($project['architect']['build'] ?? []);
        $options = (array) ($build['options'] ?? []);
        $script = (string) ($facts->json('package.json')['scripts']['build'] ?? '');
        $named = preg_match('/(?:--configuration[=\s]+|(?:^|\s)-c\s+)([\w,-]+)/', $script, $matches) === 1 ? $matches[1] : (string) ($build['defaultConfiguration'] ?? '');

        foreach (array_filter(explode(',', $named)) as $configuration) {
            $options = [...$options, ...(array) ($build['configurations'][$configuration] ?? [])];
        }

        return $options;
    }
}
