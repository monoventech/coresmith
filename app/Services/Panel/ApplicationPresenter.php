<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Panel;

use App\Enums\ApplicationStatus;
use App\Enums\ApplicationType;
use App\Enums\PowerState;
use App\Models\Application;
use App\Services\Runtime\Catalog;
use App\Services\Runtime\RuntimeLifecycle;

final class ApplicationPresenter
{
    private const FRAMEWORKS = [
        'laravel' => 'Laravel',
        'rails' => 'Rails',
        'sinatra' => 'Sinatra',
        'rack' => 'Rack',
        'hanami' => 'Hanami',
        'remix' => 'Remix',
        'express' => 'Express',
        'hono' => 'Hono',
        'symfony' => 'Symfony',
        'wordpress' => 'WordPress',
    ];

    public static function kind(Application $application): string
    {
        $framework = strtolower((string) $application->framework);

        $preset = $application->type?->language()->frameworkLabel($framework);

        if ($preset !== null) {
            $label = $preset;
        } elseif ($framework !== '' && isset(self::FRAMEWORKS[$framework])) {
            $label = self::FRAMEWORKS[$framework];
        } elseif ($framework !== '') {
            $label = ucfirst($framework);
        } else {
            $label = match ($application->type) {
                ApplicationType::Php => 'PHP',
                ApplicationType::Ruby => 'Ruby',
                ApplicationType::Python => 'Python',
                ApplicationType::Go => 'Go',
                ApplicationType::Java => 'Java',
                ApplicationType::Static => 'Static site',
                default => 'API',
            };
        }

        $detected = ((array) $application->stack)['framework'] ?? null;

        return is_array($detected) && ($detected['name'] ?? null) === $framework && isset($detected['major'])
            ? $label . ' ' . (int) $detected['major']
            : $label;
    }

    public static function runtime(Application $application): string
    {
        return match ($application->type) {
            ApplicationType::Php => trim('PHP ' . self::minor($application->php_version)),
            ApplicationType::Ruby => trim('Ruby ' . self::minor($application->ruby_version)),
            ApplicationType::Python => trim('Python ' . self::minor($application->python_version)),
            ApplicationType::Go => trim('Go ' . self::minor($application->go_version)),
            ApplicationType::Java => trim('Java ' . self::major($application->java_version)),
            ApplicationType::Static => 'Static files',
            default => match ($application->js_runtime) {
                'deno' => trim('Deno ' . self::minor($application->deno_version)),
                'node' => trim('Node ' . self::major($application->node_version)),
                default => trim('Bun ' . self::minor($application->bun_version)),
            },
        };
    }

    /**
     * @return array{runtime: string, ended: string, fix: string}|null
     */
    public static function runtimeEol(Application $application): ?array
    {
        $runtime = $application->type?->runtime($application->js_runtime);

        $version = $runtime === null ? null : ($application->runtimeVersions()[$runtime] ?? null);

        if ($runtime === null || $version === null) {
            return null;
        }

        $status = app(RuntimeLifecycle::class)->status($runtime, $version);

        if (!$status->isEol()) {
            return null;
        }

        $catalog = app(Catalog::class);
        $source = ((array) $application->stack)['runtimes'][$runtime]['source'] ?? null;

        return [
            'runtime' => $catalog->label($runtime) . ' ' . $catalog->lineOf($runtime, $version),
            'ended' => (string) $status->eolAt?->format('j F Y'),
            'fix' => self::runtimeFix($runtime, is_string($source) ? $source : null, $catalog->recommendedLine($runtime)),
        ];
    }

    private static function runtimeFix(string $runtime, ?string $source, string $recommended): string
    {
        return match ($runtime === 'java' && $source !== null ? basename($source) : $source) {
            '.tool-versions' => 'Set ' . ($runtime === 'go' ? 'golang' : $runtime) . " in .tool-versions to {$recommended}, then deploy.",
            'go.mod' => "Raise the go line in go.mod to {$recommended}, then deploy.",
            'composer.json' => "Set require.php in composer.json to ^{$recommended}, then deploy.",
            '.nvmrc', '.node-version', '.ruby-version', '.dvmrc', '.python-version', '.java-version' => "Set {$source} to {$recommended}, then deploy.",
            '.sdkmanrc' => "Set java in .sdkmanrc to a {$recommended} release, then deploy.",
            'pom.xml' => "Set the Java release in {$source} to {$recommended}, then deploy.",
            'build.gradle', 'build.gradle.kts' => "Set the Java toolchain in {$source} to {$recommended}, then deploy.",
            'package.json' => "Set engines.node in package.json to {$recommended}, then deploy.",
            'Gemfile' => "Set the ruby line in Gemfile to {$recommended}, then deploy.",
            'pyproject.toml' => "Allow Python {$recommended} in pyproject.toml, then deploy.",
            'runtime.txt' => "Set runtime.txt to python-{$recommended}, then deploy.",
            default => match ($runtime) {
                'php' => "Set require.php in composer.json to ^{$recommended}, then deploy.",
                'nodejs' => "Add a .nvmrc containing {$recommended} to the repository, then deploy.",
                'ruby' => "Add a .ruby-version containing {$recommended} to the repository, then deploy.",
                'python' => "Add a .python-version containing {$recommended} to the repository, then deploy.",
                'go' => "Add a go line naming {$recommended} to go.mod, then deploy.",
                'java' => "Add a .java-version containing {$recommended} to the repository, then deploy.",
                default => 'Name a supported version in .tool-versions, then deploy.',
            },
        };
    }

    public static function portBlock(Application $application): string
    {
        return $application->base_port . '–' . ($application->base_port + 9);
    }

    /**
     * @return array{key: string, label: string, tone: string}
     */
    public static function state(Application $application): array
    {
        return match (true) {
            $application->power_state === PowerState::Stopped => ['key' => 'stopped', 'label' => 'Stopped', 'tone' => 'outline'],
            $application->status === ApplicationStatus::Deploying => ['key' => 'deploying', 'label' => 'Deploying', 'tone' => 'accent'],
            $application->status === ApplicationStatus::Failed => ['key' => 'failed', 'label' => 'Failed', 'tone' => 'destructive'],
            $application->power_state === PowerState::Sleeping => ['key' => 'sleeping', 'label' => 'Sleeping', 'tone' => 'default'],
            (bool) (((array) $application->toggles)['maintenance_mode'] ?? false) => ['key' => 'maintenance', 'label' => 'Maintenance', 'tone' => 'warning'],
            $application->status === ApplicationStatus::Active => ['key' => 'live', 'label' => 'Live', 'tone' => 'success'],
            default => ['key' => 'pending', 'label' => 'Not deployed', 'tone' => 'default'],
        };
    }

    private static function minor(?string $version): string
    {
        if ($version === null || $version === '') {
            return '';
        }

        return preg_match('/^(\d+)\.(\d+)/', $version, $matches) === 1 ? $matches[1] . '.' . $matches[2] : $version;
    }

    private static function major(?string $version): string
    {
        if ($version === null || $version === '') {
            return '';
        }

        return preg_match('/^(\d+)/', $version, $matches) === 1 ? $matches[1] : $version;
    }
}
