<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Database;

use App\Enums\DatabaseType;
use App\Models\Application;
use App\Models\ManagedDatabase;
use App\Services\Deployment\EnvironmentFile;
use App\Services\Deployment\StackDefaults;
use Illuminate\Support\Arr;

final readonly class DatabaseConnections
{
    private const LARAVEL_STYLE = ['DB_CONNECTION', 'DB_HOST', 'DB_DATABASE', 'DB_USERNAME'];

    private const SPRING_STYLE = ['SPRING_DATASOURCE_URL', 'SPRING_DATASOURCE_USERNAME'];

    public function __construct(private EnvironmentFile $environment, private StackDefaults $defaults) {}

    /**
     * @return array<string, string>
     */
    public function variables(Application $application, ManagedDatabase $database): array
    {
        $password = $database->hasPassword() ? (string) $database->db_password : '';
        $host = (string) $database->host;
        $port = (string) $database->port;
        $style = $this->style($application);

        if ($database->type === DatabaseType::Redis) {
            return $style === 'laravel'
                ? ['REDIS_HOST' => $host, 'REDIS_PORT' => $port, 'REDIS_PASSWORD' => $password === '' ? 'null' : $password]
                : ['REDIS_URL' => 'redis://' . ($password === '' ? '' : ':' . rawurlencode($password) . '@') . $host . ':' . $port];
        }

        $postgres = $database->type === DatabaseType::Postgresql;
        $user = (string) $database->db_user;
        $name = (string) $database->name;

        return match ($style) {
            'laravel' => ['DB_CONNECTION' => $postgres ? 'pgsql' : 'mysql', 'DB_HOST' => $host, 'DB_PORT' => $port, 'DB_DATABASE' => $name, 'DB_USERNAME' => $user, 'DB_PASSWORD' => $password],
            'spring' => ['SPRING_DATASOURCE_URL' => 'jdbc:' . ($postgres ? 'postgresql' : 'mysql') . '://' . $host . ':' . $port . '/' . $name, 'SPRING_DATASOURCE_USERNAME' => $user, 'SPRING_DATASOURCE_PASSWORD' => $password],
            default => ['DATABASE_URL' => ($postgres ? 'postgresql' : 'mysql') . '://' . rawurlencode($user) . ($password === '' ? '' : ':' . rawurlencode($password)) . '@' . $host . ':' . $port . '/' . rawurlencode($name)],
        };
    }

    public function connect(Application $application, ManagedDatabase $database): void
    {
        $application->databases()->syncWithoutDetaching([$database->getKey()]);
        $this->write($application, $database);

        activity()->performedOn($application)->withProperties(['database' => $database->name, 'type' => $database->type?->value])->log('database_connected');
    }

    /**
     * @return list<string>
     */
    public function refresh(ManagedDatabase $database): array
    {
        $names = [];

        foreach ($database->applications()->orderBy('name')->get() as $application) {
            $this->write($application, $database);
            $names[] = (string) $application->name;
        }

        return $names;
    }

    /**
     * @param  list<string>  $names
     */
    public static function handedTo(array $names, string $what): string
    {
        return count($names) === 1
            ? "{$names[0]} has the new {$what}. Deploy {$names[0]} to switch over."
            : Arr::join($names, ', ', ' and ') . " have the new {$what}. Deploy them to switch over.";
    }

    private function write(Application $application, ManagedDatabase $database): void
    {
        $application->forceFill(['env_variables' => $this->defaults->fill((string) $application->env_variables, $this->variables($application, $database))])->save();
    }

    private function style(Application $application): string
    {
        $keys = array_keys($this->environment->parse((string) $application->env_variables));
        $framework = strtolower((string) $application->framework);

        return match (true) {
            $framework === 'laravel' || array_intersect(self::LARAVEL_STYLE, $keys) !== [] => 'laravel',
            in_array($framework, ['spring-boot', 'jhipster'], true) || array_intersect(self::SPRING_STYLE, $keys) !== [] => 'spring',
            default => 'url',
        };
    }
}
