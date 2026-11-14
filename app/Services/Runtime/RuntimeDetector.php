<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Runtime;

use App\Models\Application;

final class RuntimeDetector
{
    public const SOURCES = [
        'php' => '.tool-versions or require.php in composer.json',
        'nodejs' => '.tool-versions, .nvmrc, .node-version or engines.node in package.json',
        'bun' => '.tool-versions, packageManager or engines.bun in package.json',
        'deno' => '.tool-versions or .dvmrc',
        'ruby' => '.tool-versions, .ruby-version or the ruby line in Gemfile',
    ];

    private const TOOL_NAMES = ['php' => 'php', 'node' => 'nodejs', 'nodejs' => 'nodejs', 'bun' => 'bun', 'deno' => 'deno', 'ruby' => 'ruby'];

    private const NODE_CODENAMES = ['argon' => '4', 'boron' => '6', 'carbon' => '8', 'dubnium' => '10', 'erbium' => '12', 'fermium' => '14', 'gallium' => '16', 'hydrogen' => '18', 'iron' => '20', 'jod' => '22', 'krypton' => '24'];

    public function __construct(private readonly Catalog $catalog) {}

    public function detect(Application $application, RepositoryFacts $facts): Detection
    {
        $tooling = JsTooling::for($application, $facts);
        $notes = [];
        $declared = $this->toolVersions($facts, $notes);
        $runtimes = $this->runtimes($application, $tooling);
        $requirements = [];

        foreach ($runtimes as $runtime) {
            $requirement = $declared[$runtime] ?? $this->fromRuntimeFiles($runtime, $facts, $notes);

            if ($requirement !== null) {
                $requirements[$runtime] = $requirement;
            }
        }

        $limits = in_array('php', $runtimes, true) ? $this->composerLimits($facts, $notes) : [];

        return new Detection($runtimes, $requirements, $tooling, $notes, $limits === [] ? [] : ['php' => $limits]);
    }

    /**
     * @param  list<string>  $notes
     * @return list<Limit>
     */
    private function composerLimits(RepositoryFacts $facts, array &$notes): array
    {
        if ($facts->file(RepositoryProbe::COMPOSER_LOCK) === null) {
            return [];
        }

        $lock = $facts->json(RepositoryProbe::COMPOSER_LOCK);

        if (!is_array($lock) || !is_array($lock['packages'] ?? null)) {
            $notes[] = 'composer.lock could not be read, so PHP was chosen from composer.json alone.';

            return [];
        }

        $limits = [];

        foreach ($lock['packages'] as $package) {
            $php = is_array($package) ? ($package['require']['php'] ?? null) : null;

            if (is_string($php) && trim($php) !== '' && is_string($package['name'] ?? null)) {
                $limits[] = new Limit($package['name'], ltrim((string) ($package['version'] ?? ''), 'v'), trim($php));
            }
        }

        return $limits;
    }

    /**
     * @return list<string>
     */
    private function runtimes(Application $application, ?JsTooling $tooling): array
    {
        $serving = $application->type?->runtime($application->js_runtime);

        return array_values(array_unique([...($serving === null ? [] : [$serving]), ...($tooling === null ? [] : [$tooling->runtime()])]));
    }

    /**
     * @param  list<string>  $notes
     * @return array<string, Requirement>
     */
    private function toolVersions(RepositoryFacts $facts, array &$notes): array
    {
        $found = [];

        foreach (preg_split('/\r\n|\n|\r/', (string) $facts->file('.tool-versions')) ?: [] as $line) {
            $parts = preg_split('/\s+/', trim((string) preg_replace('/#.*$/', '', $line))) ?: [];
            $runtime = self::TOOL_NAMES[$parts[0] ?? ''] ?? null;
            $value = $parts[1] ?? '';

            if ($runtime === null || $value === '' || isset($found[$runtime])) {
                continue;
            }

            if (preg_match('/^v?\d/', $value) !== 1) {
                $notes[] = "Ignored {$parts[0]} {$value} in .tool-versions, because it is not a version number.";

                continue;
            }

            $found[$runtime] = new Requirement($runtime, ltrim($value, 'v'), $value, '.tool-versions', '.tool-versions', 'npm');
        }

        return $found;
    }

    /**
     * @param  list<string>  $notes
     */
    private function fromRuntimeFiles(string $runtime, RepositoryFacts $facts, array &$notes): ?Requirement
    {
        return match ($runtime) {
            'php' => $this->composerPhp($facts),
            'nodejs' => $this->nodeFile($facts, '.nvmrc', $notes) ?? $this->nodeFile($facts, '.node-version', $notes) ?? $this->engine($facts, 'node', 'nodejs'),
            'bun' => $this->bunPackageManager($facts) ?? $this->engine($facts, 'bun', 'bun'),
            'deno' => $this->firstLine($facts, '.dvmrc', 'deno'),
            'ruby' => $this->rubyVersion($facts) ?? $this->gemfileRuby($facts),
            default => null,
        };
    }

    private function composerPhp(RepositoryFacts $facts): ?Requirement
    {
        $php = $facts->json('composer.json')['require']['php'] ?? null;

        return is_string($php) && trim($php) !== ''
            ? new Requirement('php', trim($php), trim($php), 'composer.json', 'require.php in composer.json', 'composer')
            : null;
    }

    /**
     * @param  list<string>  $notes
     */
    private function nodeFile(RepositoryFacts $facts, string $file, array &$notes): ?Requirement
    {
        $raw = trim(explode("\n", (string) $facts->file($file))[0]);

        if ($raw === '') {
            return null;
        }

        $lower = strtolower($raw);

        $constraint = match (true) {
            in_array($lower, ['node', 'stable', 'latest', 'current'], true) => $this->catalog->newestLine('nodejs'),
            $lower === 'lts/*' => $this->catalog->recommendedLine('nodejs'),
            str_starts_with($lower, 'lts/') => self::NODE_CODENAMES[substr($lower, 4)] ?? null,
            preg_match('/^v?\d/', $raw) === 1 => ltrim($raw, 'v'),
            default => null,
        };

        if ($constraint === null) {
            $notes[] = "Ignored {$file}, because {$raw} is not a Node.js version Coresmith knows.";

            return null;
        }

        return new Requirement('nodejs', $constraint, $raw, $file, $file, 'npm');
    }

    private function engine(RepositoryFacts $facts, string $engine, string $runtime): ?Requirement
    {
        $value = $facts->json('package.json')['engines'][$engine] ?? null;

        return is_string($value) && trim($value) !== ''
            ? new Requirement($runtime, trim($value), trim($value), 'package.json', "engines.{$engine} in package.json", 'npm')
            : null;
    }

    private function bunPackageManager(RepositoryFacts $facts): ?Requirement
    {
        $declared = (string) ($facts->json('package.json')['packageManager'] ?? '');

        if (preg_match('/^bun@(\d+\.\d+\.\d+)/', $declared, $matches) !== 1) {
            return null;
        }

        return new Requirement('bun', $matches[1], $declared, 'package.json', 'packageManager in package.json', 'npm');
    }

    private function firstLine(RepositoryFacts $facts, string $file, string $runtime): ?Requirement
    {
        $raw = trim(explode("\n", (string) $facts->file($file))[0]);

        return preg_match('/^v?\d/', $raw) === 1 ? new Requirement($runtime, ltrim($raw, 'v'), $raw, $file, $file, 'npm') : null;
    }

    private function rubyVersion(RepositoryFacts $facts): ?Requirement
    {
        $raw = trim(explode("\n", (string) $facts->file('.ruby-version'))[0]);
        $version = (string) preg_replace('/^ruby-/', '', $raw);

        return preg_match('/^\d/', $version) === 1 ? new Requirement('ruby', $version, $raw, '.ruby-version', '.ruby-version', 'gem') : null;
    }

    private function gemfileRuby(RepositoryFacts $facts): ?Requirement
    {
        if (preg_match('/^\s*ruby\s+["\']([^"\']+)["\']/m', (string) $facts->file('Gemfile'), $matches) !== 1) {
            return null;
        }

        return new Requirement('ruby', trim($matches[1]), trim($matches[1]), 'Gemfile', 'the ruby line in Gemfile', 'gem');
    }
}
