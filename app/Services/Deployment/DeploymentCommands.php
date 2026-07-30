<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Deployment;

use App\Enums\ApplicationType;
use App\Models\Application;
use App\Services\Runtime\AsdfManager;

final class DeploymentCommands
{
    public function __construct(private readonly AsdfManager $asdf) {}

    public function pathPrefix(Application $application): string
    {
        $directories = [];

        if ($application->php_version !== null) {
            $directories[] = dirname($this->asdf->binaryPath('php', $application->php_version));
        }

        if ($application->node_version !== null) {
            $directories[] = dirname($this->asdf->binaryPath('nodejs', $application->node_version));
        }

        if ($application->ruby_version !== null) {
            $directories[] = dirname($this->asdf->binaryPath('ruby', $application->ruby_version));
        }

        return $directories === [] ? '' : 'PATH='.implode(':', $directories).':$PATH ';
    }

    /**
     * @return list<string>
     */
    public function dependencies(Application $application): array
    {
        return match ($application->type) {
            ApplicationType::Php => ['composer install --no-dev --optimize-autoloader --no-interaction'],
            ApplicationType::Js => [$application->js_runtime === 'node' ? 'npm ci' : 'bun install --frozen-lockfile'],
            ApplicationType::Ruby => ['bundle install --deployment --without development test'],
            ApplicationType::Static => [],
        };
    }

    /**
     * @return list<string>
     */
    public function build(Application $application): array
    {
        return match ($application->type) {
            ApplicationType::Php => $application->framework === 'laravel'
                ? ['php artisan config:cache', 'php artisan route:cache', 'php artisan view:cache']
                : [],
            ApplicationType::Js => [$application->js_runtime === 'node' ? 'npm run build' : 'bun run build'],
            ApplicationType::Ruby => ['RAILS_ENV=production bundle exec rails assets:precompile'],
            ApplicationType::Static => [],
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
            ApplicationType::Php => $application->framework === 'laravel' ? ['php artisan migrate --force --isolated'] : [],
            ApplicationType::Ruby => ['RAILS_ENV=production bundle exec rails db:migrate'],
            default => [],
        };
    }

    /**
     * @return list<string>
     */
    public function restart(Application $application): array
    {
        $slug = $application->slug;

        $units = match ($application->type) {
            ApplicationType::Php => ['systemctl reload php-fpm-'.$application->system_user],
            ApplicationType::Static => [],
            default => ['systemctl restart coresmith-app-'.$slug.'-web'],
        };

        if ($application->processes !== null && $application->processes !== []) {
            $units[] = 'systemctl restart coresmith-app-'.$slug.'-worker';
        }

        return $units;
    }
}
