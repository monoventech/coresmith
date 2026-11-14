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
use App\Services\Runtime\RepositoryProbe;
use App\Services\Runtime\ResolvedRuntime;
use App\Services\Runtime\Stack;
use App\Services\Runtime\VersionConstraint;

final readonly class Php implements Language
{
    public const LARAVEL_CACHES = ['php artisan config:cache', 'php artisan route:cache', 'php artisan view:cache'];

    public const LIBRARY = 'composer.json names no framework and there is no index.php at /, /public or /web, so this looks like a library, not an app.';

    public const LOCK_UNREADABLE = "composer.lock couldn't be read whole, so Coresmith can't tell which PHP versions its packages allow. Pick the PHP version yourself.";

    public const PHP_UNNAMED = 'composer.json names no PHP version and there is no composer.lock, so Coresmith can\'t tell which PHP your packages allow. Add one to require in composer.json, for example "php": "^8.3", or commit composer.lock.';

    private const SYMFONY_FAMILY = ['symfony/', 'oro/', 'sylius/', 'shopware/', 'pimcore/'];

    private const PHP_INDEXES = ['public' => 'public/index.php', 'web' => 'web/index.php', '' => 'index.php'];

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

    public function storageSeed(): StorageSeed
    {
        return StorageSeed::FromRelease;
    }

    public function initialEnvironment(?string $framework): array
    {
        return match ($framework) {
            'laravel' => ['APP_KEY' => 'base64:' . base64_encode(random_bytes(32))],
            'symfony' => ['APP_ENV' => 'prod', 'APP_SECRET' => bin2hex(random_bytes(16))],
            default => [],
        };
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
     * @return array{type: ?string, framework: ?string, js_runtime: ?string, web: ?string, sources: array<string, string>, unsettled: array<string, string>, fields?: array<string, ?string>, options?: array<string, list<string>>}|null
     */
    public function detect(RepositoryFacts $facts): ?array
    {
        $index = match (true) {
            $facts->exists('public/index.php') => '/public',
            $facts->exists('web/index.php') => '/web',
            $facts->exists('index.php') => '/',
            default => null,
        };

        if (!$facts->has('composer.json')) {
            return $index === null || $this->otherServer($facts) ? null : Proposal::make('php', '', null, $index, [
                'type' => 'From index.php',
                'web_directory' => $this->indexSource($facts, $index),
            ]);
        }

        $require = (array) ($facts->json('composer.json')['require'] ?? []);
        $laravel = array_key_exists('laravel/framework', $require) || $facts->locked('laravel/framework') !== null;

        if ($laravel && $facts->exists('artisan')) {
            return Proposal::make('php', 'laravel', null, '/public', [
                'type' => 'From composer.json',
                'framework' => $this->composerSource($facts, 'laravel/framework', $require['laravel/framework'] ?? null),
                'web_directory' => 'Laravel serves public/index.php',
            ]);
        }

        if ($laravel && $index === null) {
            return Proposal::unsure('type', self::LIBRARY);
        }

        $symfony = $this->symfony($facts, $require);

        if ($symfony !== null && $index === null && !$facts->exists('bin/console')) {
            return Proposal::unsure('type', self::LIBRARY);
        }

        if ($symfony !== null) {
            return Proposal::make('php', 'symfony', null, '/public', [
                'type' => 'From composer.json',
                'framework' => $symfony,
                'web_directory' => 'Symfony serves public/index.php',
            ]);
        }

        if ($index !== null) {
            return Proposal::make('php', '', null, $index, ['type' => 'From composer.json', 'framework' => 'No framework in composer.json', 'web_directory' => $this->indexSource($facts, $index)]);
        }

        return $this->otherServer($facts) ? null : Proposal::unsure('type', self::LIBRARY);
    }

    /**
     * @return list<Blocker>
     */
    public function checks(Application $choice, string $framework, RepositoryFacts $facts, ?string $arch): array
    {
        $blockers = [];
        $name = $framework === 'symfony' ? 'Symfony' : 'Laravel';

        if (in_array($framework, ['laravel', 'symfony'], true) && !$facts->has('composer.json')) {
            $blockers[] = new Blocker('framework', "There is no composer.json, so this isn't a {$name} app {$facts->at()}.", 'Pick Plain PHP.');
        } elseif ($framework === 'laravel' && !$facts->exists('artisan')) {
            $blockers[] = new Blocker('framework', "There is no artisan file {$facts->at()}, so this isn't a Laravel app.", 'Pick Plain PHP, or Symfony if it uses Symfony.');
        }

        $platform = $this->platformBehindLock($facts);

        if ($platform !== null) {
            $blockers[] = $platform;
        }

        $web = trim((string) $choice->web_directory, '/');
        $index = self::PHP_INDEXES[$web] ?? null;

        if ($index !== null && !$facts->exists($index)) {
            $found = array_search(true, array_map(fn(string $path): bool => $facts->exists($path), self::PHP_INDEXES), true);
            $blockers[] = new Blocker(
                'web_directory',
                "There is no {$index}, so the web root /{$web} would serve nothing.",
                is_string($found) ? 'Set the web root to /' . $found . ', where index.php is.' : 'Set the web root to the folder that holds index.php.',
            );
        }

        return $blockers;
    }

    public function versionDoubt(RepositoryFacts $facts, ?ResolvedRuntime $runtime): ?string
    {
        return match (true) {
            $facts->has(RepositoryProbe::COMPOSER_LOCK) && !is_array($facts->json(RepositoryProbe::COMPOSER_LOCK)['packages'] ?? null) => self::LOCK_UNREADABLE,
            $facts->has('composer.json') && !$facts->has(RepositoryProbe::COMPOSER_LOCK) && $runtime?->source === ResolvedRuntime::FROM_DEFAULT => self::PHP_UNNAMED,
            default => null,
        };
    }

    public function cronExample(Application $application): string
    {
        return match (strtolower((string) $application->framework)) {
            'laravel' => 'php artisan backup:run',
            'symfony' => 'php bin/console app:send-reports',
            default => 'php cron/cleanup.php',
        };
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

    public function explain(string $phase, string $output, Stack $stack): ?string
    {
        return null;
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
     * @param  array<int|string, mixed>  $require
     */
    private function symfony(RepositoryFacts $facts, array $require): ?string
    {
        return match (true) {
            array_key_exists('symfony/framework-bundle', $require) => $this->composerSource($facts, 'symfony/framework-bundle', $require['symfony/framework-bundle']),
            $facts->locked('symfony/framework-bundle') !== null => 'symfony/framework-bundle ' . $facts->locked('symfony/framework-bundle') . ' in composer.lock',
            $facts->exists('symfony.lock'), $facts->exists('config/bundles.php') => 'symfony.lock and config/bundles.php',
            $facts->exists('bin/console') && array_filter(array_keys($require), fn(int|string $package): bool => array_filter(self::SYMFONY_FAMILY, fn(string $family): bool => str_starts_with((string) $package, $family)) !== []) !== [] => 'bin/console with Symfony packages in composer.json',
            default => null,
        };
    }

    private function platformBehindLock(RepositoryFacts $facts): ?Blocker
    {
        $platform = $facts->json('composer.json')['config']['platform']['php'] ?? null;
        $packages = $facts->json(RepositoryProbe::COMPOSER_LOCK)['packages'] ?? null;

        if (!is_string($platform) || preg_match('/^\d+(?:\.\d+){0,2}$/', $platform) !== 1 || !is_array($packages)) {
            return null;
        }

        foreach ($packages as $package) {
            $needs = is_array($package) && is_array($package['require'] ?? null) ? ($package['require']['php'] ?? null) : null;
            $constraint = is_string($needs) ? VersionConstraint::parse($needs, 'composer') : null;

            if ($constraint !== null && !$constraint->satisfiedBy($platform)) {
                $locked = trim((string) ($package['name'] ?? '') . ' ' . ltrim((string) ($package['version'] ?? ''), 'v'));

                return new Blocker(
                    'repository',
                    "composer.json sets config.platform.php to {$platform}, but composer.lock holds {$locked}, which needs PHP {$needs}, so composer install fails on any server.",
                    'Raise config.platform.php in composer.json to a PHP composer.lock allows, or remove it, then run composer update --lock and commit both files.',
                );
            }
        }

        return null;
    }

    private function otherServer(RepositoryFacts $facts): bool
    {
        return $facts->has('Gemfile') || $facts->has('package.json') || $facts->has('deno.json') || $facts->has('deno.jsonc');
    }

    private function composerSource(RepositoryFacts $facts, string $package, mixed $constraint): string
    {
        $locked = $facts->locked($package);

        return $locked === null ? "{$package} " . (is_string($constraint) ? $constraint : '') . ' in composer.json' : "{$package} {$locked} in composer.lock";
    }

    private function indexSource(RepositoryFacts $facts, string $index): string
    {
        return $index === '/' ? "index.php is {$facts->at()}" : "index.php is in {$index}";
    }
}
