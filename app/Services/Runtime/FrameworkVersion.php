<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Runtime;

use App\Models\Application;

final class FrameworkVersion
{
    private const SOURCES = [
        'laravel' => ['composer', 'laravel/framework'],
        'symfony' => ['composer', 'symfony/framework-bundle'],
        'rails' => ['gem', 'rails'],
        'sveltekit' => ['npm', '@sveltejs/kit'],
        'nextjs' => ['npm', 'next'],
        'next' => ['npm', 'next'],
        'nuxt' => ['npm', 'nuxt'],
        'astro' => ['npm', 'astro'],
    ];

    /**
     * @return array{name: string, major: int}|null
     */
    public function detect(Application $application, RepositoryFacts $facts): ?array
    {
        $framework = strtolower((string) $application->framework);
        $source = self::SOURCES[$framework] ?? null;

        if ($source === null) {
            return null;
        }

        [$ecosystem, $package] = $source;

        $version = $facts->locked($package) ?? match ($ecosystem) {
            'composer' => $facts->json('composer.json')['require'][$package] ?? null,
            'npm' => $facts->json('package.json')['dependencies'][$package] ?? $facts->json('package.json')['devDependencies'][$package] ?? null,
            default => preg_match('/^\s*gem\s+["\']' . preg_quote($package, '/') . '["\']\s*,\s*["\']([^"\']+)["\']/m', (string) $facts->file('Gemfile'), $matches) === 1 ? $matches[1] : null,
        };

        if (!is_string($version) || preg_match('/(\d+)/', $version, $major) !== 1) {
            return null;
        }

        return ['name' => $framework, 'major' => (int) $major[1]];
    }
}
