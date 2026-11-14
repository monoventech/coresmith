<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Detection;

use App\Exceptions\RuntimeUnavailable;
use App\Models\Application;
use App\Services\Runtime\JsTooling;
use App\Services\Runtime\RepositoryFacts;
use App\Services\Runtime\RepositoryProbe;
use App\Services\Runtime\StackResolver;
use Closure;

final class StackChecks
{
    public function __construct(private readonly StackResolver $resolver) {}

    /**
     * @param  Closure(string): list<string>  $installed
     * @param  (Closure(string): ?list<string>)|null  $extensions
     * @return list<Blocker>
     */
    public function blockers(Application $choice, RepositoryFacts $facts, Closure $installed, bool $runtime = true, ?Closure $extensions = null, ?string $arch = null): array
    {
        if (!$facts->complete || trim((string) $choice->custom_blueprint) !== '') {
            return [];
        }

        $framework = strtolower((string) $choice->framework);

        $blockers = $choice->type?->language()->checks($choice, $framework, $facts, $arch) ?? [];

        $mismatch = $this->managerMismatch($facts);
        $blockers = $mismatch === null ? $blockers : [...$blockers, $mismatch];

        return $runtime ? [...$blockers, ...$this->runtime($choice, $facts, $installed, $extensions)] : $blockers;
    }

    private function managerMismatch(RepositoryFacts $facts): ?Blocker
    {
        $declared = JsTooling::declaredManager($facts);
        $lockfile = JsTooling::lockfileFor($facts);
        $locked = match ($lockfile) {
            'pnpm-lock.yaml' => 'pnpm',
            'yarn.lock' => 'yarn',
            default => null,
        };

        if ($declared === null || $locked === null || $declared === $locked) {
            return null;
        }

        return new Blocker(
            'repository',
            "package.json names {$declared} in packageManager, but the lockfile is {$lockfile}, and corepack refuses to install with a different package manager.",
            'Commit a ' . JsTooling::LOCKFILES[$declared][0] . " made with {$declared}, or change packageManager to {$locked}.",
        );
    }

    /**
     * @param  Closure(string): list<string>  $installed
     * @param  (Closure(string): ?list<string>)|null  $extensions
     * @return list<Blocker>
     */
    private function runtime(Application $choice, RepositoryFacts $facts, Closure $installed, ?Closure $extensions): array
    {
        if ($choice->type === null) {
            return [];
        }

        try {
            $stack = $this->resolver->resolve($choice, $facts, $installed);
        } catch (RuntimeUnavailable $refusal) {
            return [new Blocker('runtime_version', $refusal->getMessage())];
        }

        $php = $stack->versions()['php'] ?? null;
        $modules = $php === null || $extensions === null ? null : $extensions($php);
        $missing = $modules === null ? null : $this->missingExtension($facts, $modules);

        return $missing === null ? [] : [new Blocker('repository', "{$missing[0]} needs the {$missing[1]} PHP extension, which PHP {$php} on this server doesn't have, so composer install fails.", $missing[2])];
    }

    /**
     * @param  list<string>  $modules
     * @return array{string, string, string}|null
     */
    private function missingExtension(RepositoryFacts $facts, array $modules): ?array
    {
        $have = array_map(self::extensionName(...), $modules);
        $have = in_array('zend_opcache', $have, true) ? [...$have, 'opcache'] : $have;
        $packages = $facts->json(RepositoryProbe::COMPOSER_LOCK)['packages'] ?? [];
        $packages = is_array($packages) ? array_values(array_filter($packages, is_array(...))) : [];
        $provided = [];
        $needers = [['composer.json', (array) ($facts->json('composer.json')['require'] ?? []), null]];

        foreach ($packages as $package) {
            $name = (string) ($package['name'] ?? '');
            $needers[] = [trim($name . ' ' . ltrim((string) ($package['version'] ?? ''), 'v')) . ' in composer.lock', (array) ($package['require'] ?? []), $name];

            foreach (array_keys((array) ($package['provide'] ?? [])) as $provide) {
                if (str_starts_with(strtolower((string) $provide), 'ext-')) {
                    $provided[] = self::extensionName(substr((string) $provide, 4));
                }
            }
        }

        foreach ($needers as [$needer, $require, $package]) {
            foreach (array_keys($require) as $requirement) {
                $requirement = (string) $requirement;

                if (!str_starts_with(strtolower($requirement), 'ext-')) {
                    continue;
                }

                $extension = strtolower(substr($requirement, 4));

                if (!in_array(self::extensionName($extension), [...$have, ...$provided], true)) {
                    return [$needer, $extension, $package === null
                        ? "Remove {$requirement} from composer.json if the app runs without it."
                        : "Remove {$package} with composer remove if the app runs without it, then commit composer.lock."];
                }
            }
        }

        return null;
    }

    private static function extensionName(string $name): string
    {
        return str_replace('-', '_', strtolower(trim($name)));
    }
}
