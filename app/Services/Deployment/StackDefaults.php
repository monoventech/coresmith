<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Deployment;

use App\Enums\ApplicationType;
use App\Models\Application;
use App\Services\Runtime\RepositoryFacts;

final class StackDefaults
{
    private const SECRETS = ['APP_KEY', 'APP_SECRET', 'SECRET_KEY_BASE'];

    /**
     * @return array<string, string>
     */
    public function initial(?ApplicationType $type, ?string $framework): array
    {
        return match (true) {
            $type === ApplicationType::Php && $framework === 'laravel' => ['APP_KEY' => 'base64:' . base64_encode(random_bytes(32))],
            $type === ApplicationType::Php && $framework === 'symfony' => ['APP_ENV' => 'prod', 'APP_SECRET' => bin2hex(random_bytes(16))],
            $type === ApplicationType::Ruby && $framework === 'rails' => ['SECRET_KEY_BASE' => bin2hex(random_bytes(64))],
            default => [],
        };
    }

    /**
     * @return array<string, string>
     */
    public function missing(Application $application, RepositoryFacts $facts): array
    {
        $set = array_filter(app(EnvironmentFile::class)->parse((string) $application->env_variables), fn(string $value): bool => trim($value) !== '');
        $secrets = array_intersect_key($this->initial($application->type, $application->framework === null ? null : strtolower($application->framework)), array_flip(self::SECRETS));

        if (isset($secrets['SECRET_KEY_BASE']) && (isset($set['RAILS_MASTER_KEY']) || in_array('RAILS_MASTER_KEY', $facts->envKeys, true) || $facts->exists('config/master.key'))) {
            unset($secrets['SECRET_KEY_BASE']);
        }

        return array_filter($secrets, fn(string $value, string $name): bool => !isset($set[$name]) && !in_array($name, $facts->envKeys, true), ARRAY_FILTER_USE_BOTH);
    }

    /**
     * @return array<string, string>
     */
    public function process(Application $application): array
    {
        $framework = strtolower((string) $application->framework);

        return match ($application->type) {
            ApplicationType::Js => [
                'NODE_ENV' => 'production',
                ...($framework === 'sveltekit' ? ['PROTOCOL_HEADER' => 'x-forwarded-proto', 'HOST_HEADER' => 'x-forwarded-host', 'ADDRESS_HEADER' => 'x-forwarded-for'] : []),
            ],
            ApplicationType::Ruby => [
                'RAILS_ENV' => 'production',
                'RACK_ENV' => 'production',
                ...($framework === 'sinatra' ? [] : ['RAILS_SERVE_STATIC_FILES' => '1', 'RAILS_LOG_TO_STDOUT' => '1']),
            ],
            default => [],
        };
    }
}
