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

final class ApplicationPresenter
{
    private const FRAMEWORKS = [
        'laravel' => 'Laravel',
        'rails' => 'Rails',
        'sinatra' => 'Sinatra',
        'hanami' => 'Hanami',
        'sveltekit' => 'SvelteKit',
        'nextjs' => 'Next.js',
        'next' => 'Next.js',
        'nuxt' => 'Nuxt',
        'astro' => 'Astro',
        'remix' => 'Remix',
        'express' => 'Express',
        'hono' => 'Hono',
        'symfony' => 'Symfony',
        'wordpress' => 'WordPress',
    ];

    public static function kind(Application $application): string
    {
        $framework = strtolower((string) $application->framework);

        if ($framework !== '' && isset(self::FRAMEWORKS[$framework])) {
            return self::FRAMEWORKS[$framework];
        }

        if ($framework !== '') {
            return ucfirst($framework);
        }

        return match ($application->type) {
            ApplicationType::Php => 'PHP',
            ApplicationType::Ruby => 'Ruby',
            ApplicationType::Static => 'Static site',
            default => 'API',
        };
    }

    public static function runtime(Application $application): string
    {
        return match ($application->type) {
            ApplicationType::Php => trim('PHP ' . self::minor($application->php_version)),
            ApplicationType::Ruby => trim('Ruby ' . self::minor($application->ruby_version)),
            ApplicationType::Static => 'Caddy',
            default => match ($application->js_runtime) {
                'deno' => 'Deno',
                'node' => trim('Node ' . self::minor($application->node_version)),
                default => 'Bun',
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
}
