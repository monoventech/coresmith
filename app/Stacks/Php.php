<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Stacks;

use App\Models\Application;
use App\Services\Deployment\ReleasePaths;
use App\Services\Runtime\Stack;

final readonly class Php implements Language
{
    public const LARAVEL_CACHES = ['php artisan config:cache', 'php artisan route:cache', 'php artisan view:cache'];

    public function __construct(private JsCommands $js) {}

    public function install(Application $application, Stack $stack): array
    {
        $composer = $this->composer($stack);
        $env = $application->framework === 'symfony' || (blank($application->framework) && $composer !== []) ? ['[ -e .env ] || touch .env'] : [];

        return [...$env, ...$composer, ...$this->js->install($stack)];
    }

    public function build(Application $application, Stack $stack): array
    {
        return [...$this->js->build($application, $stack), ...($application->framework === 'laravel' ? self::LARAVEL_CACHES : [])];
    }

    public function migrations(Application $application, Stack $stack): array
    {
        return $application->framework === 'laravel' ? ['php artisan migrate --force'] : [];
    }

    public function releaseChecks(Application $application, ReleasePaths $paths, string $release): array
    {
        $web = trim((string) $application->web_directory, '/');

        return [($web === '' ? '' : $web . '/') . 'index.php' => 'test -f ' . escapeshellarg($paths->webRoot($release) . '/index.php')];
    }

    public function webCommand(Application $application): ?string
    {
        return null;
    }

    public function usesFpm(): bool
    {
        return true;
    }

    public function route(Application $application): Route
    {
        return Route::PhpFastcgi;
    }

    /**
     * @return list<string>
     */
    private function composer(Stack $stack): array
    {
        $install = 'composer install --no-dev --optimize-autoloader --no-interaction';

        return match (true) {
            $stack->present('composer.json') === false => [],
            $stack->present('composer.lock') === false => [
                $install . ' --no-scripts',
                'composer dump-autoload --optimize --no-dev --no-interaction',
                'if composer config scripts.post-install-cmd >/dev/null 2>&1; then composer run-script post-install-cmd --no-dev --no-interaction; fi',
            ],
            default => [$install],
        };
    }
}
