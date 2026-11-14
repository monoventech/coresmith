<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Deployment;

use App\Enums\ApplicationType;
use App\Models\Application;
use App\Services\Runtime\RepositoryFacts;
use App\Stacks\Java;

final class StackDefaults
{
    private const SECRETS = ['APP_KEY', 'APP_SECRET', 'SECRET_KEY_BASE', 'SECRET_KEY', Java::JHIPSTER_SECRET];

    /**
     * @return array<string, string>
     */
    public function initial(?ApplicationType $type, ?string $framework): array
    {
        return $type?->language()->initialEnvironment($framework) ?? [];
    }

    /**
     * @return array<string, string>
     */
    public function missing(Application $application, RepositoryFacts $facts): array
    {
        if (!$facts->complete) {
            return [];
        }

        $set = array_filter(app(EnvironmentFile::class)->parse((string) $application->env_variables), fn(string $value): bool => trim($value) !== '');
        $secrets = array_intersect_key($this->initial($application->type, $application->framework === null ? null : strtolower($application->framework)), array_flip(self::SECRETS));

        if (isset($secrets['SECRET_KEY_BASE']) && (isset($set['RAILS_MASTER_KEY']) || in_array('RAILS_MASTER_KEY', $facts->envKeys, true) || $facts->exists('config/master.key'))) {
            unset($secrets['SECRET_KEY_BASE']);
        }

        return array_filter($secrets, fn(string $value, string $name): bool => !isset($set[$name]) && !in_array($name, $facts->envKeys, true), ARRAY_FILTER_USE_BOTH);
    }

    /**
     * @param  array<string, string>  $values
     */
    public function fill(string $environment, array $values): string
    {
        foreach ($values as $name => $value) {
            $line = $name . '="' . addcslashes($value, '\\"$`') . '"';
            $pattern = '/^[ \t]*(?:export[ \t]+)?' . preg_quote($name, '/') . '[ \t]*=.*$/m';

            $environment = preg_match($pattern, $environment) === 1
                ? (string) preg_replace_callback($pattern, fn(): string => $line, $environment, 1)
                : ($environment === '' || str_ends_with($environment, "\n") ? $environment : $environment . "\n") . $line . "\n";
        }

        return $environment;
    }

    /**
     * @return array<string, string>
     */
    public function build(Application $application): array
    {
        return [
            'COREPACK_ENABLE_DOWNLOAD_PROMPT' => '0',
            ...(strtolower((string) $application->framework) === 'laravel' ? ['LARAVEL_INSTALLER_DEFER_HOOKS' => '1'] : []),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function process(Application $application): array
    {
        return $application->type->language()->processEnvironment($application);
    }
}
