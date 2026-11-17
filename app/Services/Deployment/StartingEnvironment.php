<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Deployment;

use App\Enums\ApplicationType;
use App\Enums\DatabaseType;
use App\Models\Application;
use App\Services\Runtime\RepositoryFacts;

final readonly class StartingEnvironment
{
    public const EXAMPLES = ['.env.example', '.env.sample', '.env.dist', '.env.template'];

    public const REDIS_SETTINGS = ['CACHE_STORE', 'CACHE_DRIVER', 'QUEUE_CONNECTION', 'SESSION_DRIVER', 'BROADCAST_CONNECTION', 'BROADCAST_DRIVER'];

    private const CONNECTION = ['DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'DB_URL', 'DB_SOCKET', 'DATABASE_URL', 'REDIS_HOST', 'REDIS_PORT', 'REDIS_PASSWORD', 'REDIS_USERNAME', 'REDIS_URL'];

    private const MODES = ['APP_ENV', 'APP_DEBUG', 'NODE_ENV', 'RAILS_ENV', 'RACK_ENV', 'DEBUG', 'DJANGO_DEBUG', 'FLASK_ENV', 'FLASK_DEBUG', 'GIN_MODE', 'GO_ENV', 'SPRING_PROFILES_ACTIVE'];

    private const MANAGED = ['REVERB_SERVER_HOST', 'REVERB_SERVER_PORT', 'REVERB_HOST', 'REVERB_PORT', 'REVERB_SCHEME', 'VITE_REVERB_HOST', 'VITE_REVERB_PORT', 'VITE_REVERB_SCHEME', 'VITE_REVERB_APP_KEY', 'INERTIA_SSR_ENABLED', 'INERTIA_SSR_URL'];

    private const SECRET = '/(SECRET|PASSWORD|PASSWD|TOKEN|SALT|PRIVATE|DSN|(^|_)KEY$|_KEY_)/';

    public function __construct(private EnvironmentFile $environment, private StackDefaults $defaults) {}

    public function example(?RepositoryFacts $facts): ?string
    {
        foreach (self::EXAMPLES as $file) {
            if ($facts?->file($file) !== null) {
                return $file;
            }
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    public function exampleVariables(?RepositoryFacts $facts): array
    {
        $file = $this->example($facts);

        return $file === null ? [] : $this->environment->parse((string) $facts?->file($file));
    }

    public function database(?RepositoryFacts $facts): ?DatabaseType
    {
        $connection = strtolower(trim($this->exampleVariables($facts)['DB_CONNECTION'] ?? ''));

        return match ($connection) {
            'pgsql', 'postgres', 'postgresql' => DatabaseType::Postgresql,
            'mysql', 'mariadb' => DatabaseType::Mysql,
            default => null,
        };
    }

    public function wantsRedis(?RepositoryFacts $facts): bool
    {
        $variables = $this->exampleVariables($facts);

        foreach (self::REDIS_SETTINGS as $setting) {
            if (strtolower(trim($variables[$setting] ?? '')) === 'redis') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{example: ?string, database: ?string, redis: bool}
     */
    public function suggestion(?RepositoryFacts $facts): array
    {
        return ['example' => $this->example($facts), 'database' => $this->database($facts)?->value, 'redis' => $this->wantsRedis($facts)];
    }

    /**
     * @return array<string, string>
     */
    public function variables(?RepositoryFacts $facts, ?ApplicationType $type, ?string $framework, ?string $domain): array
    {
        $variables = [];

        foreach (array_diff_key($this->exampleVariables($facts), array_flip([...self::CONNECTION, ...self::MODES, ...self::MANAGED, ...$this->stackKeys($type, $framework)])) as $name => $value) {
            $variables[$name] = preg_match(self::SECRET, $name) === 1 ? '' : $value;
        }

        $variables = [...$variables, ...$this->defaults->initial($type, $framework)];

        if ($this->example($facts) !== null && strtolower((string) $framework) === 'laravel') {
            $variables = [...$variables, 'APP_ENV' => 'production', 'APP_DEBUG' => 'false', ...($domain === null || $domain === '' ? [] : ['APP_URL' => 'https://' . $domain])];
        }

        return $variables;
    }

    /**
     * @return list<string>
     */
    private function stackKeys(?ApplicationType $type, ?string $framework): array
    {
        if ($type === null) {
            return [];
        }

        $stub = (new Application)->forceFill(['type' => $type, 'framework' => $framework, 'base_port' => 0]);

        return [...array_keys($type->language()->listenEnvironment($stub)), ...array_keys($type->language()->processEnvironment($stub))];
    }
}
