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
    public const LARAVEL_CACHES = ['php artisan config:cache', 'php artisan route:cache', 'php artisan view:cache'];

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
            ApplicationType::Php => [...($application->framework === 'symfony' ? ['[ -e .env ] || touch .env'] : []), ...$this->composer($stack), ...$javascript],
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
            ApplicationType::Php => [...$javascript, ...($application->framework === 'laravel' ? self::LARAVEL_CACHES : [])],
            ApplicationType::Ruby => $this->rubyBuild($application, $stack),
            default => $javascript,
        };
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

    /**
     * @return list<string>
     */
    private function rubyBuild(Application $application, Stack $stack): array
    {
        if ($application->framework === 'sinatra' || ($stack->locked('sprockets-rails') === false && $stack->locked('propshaft') === false)) {
            return [];
        }

        return ['RAILS_ENV=production bundle exec rails assets:precompile'];
    }

    /**
     * @return list<string>
     */
    public function migrations(Application $application, Stack $stack): array
    {
        if (!$application->run_migrations) {
            return [];
        }

        return match ($application->type) {
            ApplicationType::Php => $application->framework === 'laravel' ? ['php artisan migrate --force'] : [],
            ApplicationType::Ruby => $application->framework !== 'sinatra'
                ? ['RAILS_ENV=production bundle exec rails db:migrate']
                : ($stack->locked('sinatra-activerecord') === true && $stack->present('Rakefile') === true ? ['RACK_ENV=production bundle exec rake db:migrate'] : []),
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
            ApplicationType::Js => $application->js_runtime === 'deno'
                ? ['deno.json, deno.jsonc or package.json' => implode(' || ', array_map(fn(string $file): string => 'test -f ' . escapeshellarg($base . '/' . $file), ['deno.json', 'deno.jsonc', 'package.json']))]
                : ['package.json' => 'test -f ' . escapeshellarg($base . '/package.json')],
            ApplicationType::Ruby => ['Gemfile' => 'test -f ' . escapeshellarg($base . '/Gemfile')],
        };
    }
}
