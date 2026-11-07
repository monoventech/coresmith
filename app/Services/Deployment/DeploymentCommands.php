<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Deployment;

use App\Enums\ApplicationType;
use App\Models\Application;
use App\Services\Panel\SmartToggles;
use App\Services\Runtime\AsdfManager;
use App\Services\Runtime\Stack;

final class DeploymentCommands
{
    public function __construct(private readonly AsdfManager $asdf) {}

    /**
     * @param  array<string, string>  $versions
     */
    public function pathPrefix(array $versions): string
    {
        $directories = [];

        foreach ($versions as $runtime => $version) {
            AsdfManager::assertValid($runtime, $version);
            $directories[] = $this->asdf->versionPath($runtime, $version) . '/bin';
        }

        return $directories === [] ? '' : 'PATH=' . implode(':', $directories) . ':$PATH ';
    }

    /**
     * @return list<string>
     */
    public function dependencies(Application $application, Stack $stack): array
    {
        $javascript = $stack->tooling?->install() ?? [];

        return match ($application->type) {
            ApplicationType::Php => ['composer install --no-dev --optimize-autoloader --no-interaction', ...$javascript],
            ApplicationType::Ruby => ['bundle config set --local deployment true', "bundle config set --local without 'development test'", 'bundle install'],
            default => $javascript,
        };
    }

    /**
     * @return list<string>
     */
    public function build(Application $application, Stack $stack): array
    {
        $javascript = $stack->tooling?->buildCommands(app(SmartToggles::class)->ssrBuilds($application, $stack)) ?? [];

        return match ($application->type) {
            ApplicationType::Php => [...$javascript, ...($application->framework === 'laravel' ? ['php artisan config:cache', 'php artisan route:cache', 'php artisan view:cache'] : [])],
            ApplicationType::Ruby => ['RAILS_ENV=production bundle exec rails assets:precompile'],
            default => $javascript,
        };
    }

    /**
     * @return list<string>
     */
    public function migrations(Application $application): array
    {
        if (!$application->run_migrations) {
            return [];
        }

        return match ($application->type) {
            ApplicationType::Php => $application->framework === 'laravel' ? ['php artisan migrate --force'] : [],
            ApplicationType::Ruby => ['RAILS_ENV=production bundle exec rails db:migrate'],
            default => [],
        };
    }

    /**
     * @return array<string, string>
     */
    public function healthChecks(Application $application, ReleasePaths $paths, string $release): array
    {
        $root = $paths->webRoot($release);
        $web = trim((string) $application->web_directory, '/');
        $base = rtrim($release . '/' . trim((string) $application->directory, '/'), '/');

        return match ($application->type) {
            ApplicationType::Php => [($web === '' ? '' : $web . '/') . 'index.php' => 'test -f ' . escapeshellarg($root . '/index.php')],
            ApplicationType::Static => [($web === '' ? 'the release root' : $web . '/') => 'test -d ' . escapeshellarg($root)],
            ApplicationType::Js => ['package.json' => 'test -f ' . escapeshellarg($base . '/package.json')],
            ApplicationType::Ruby => ['Gemfile' => 'test -f ' . escapeshellarg($base . '/Gemfile')],
        };
    }
}
