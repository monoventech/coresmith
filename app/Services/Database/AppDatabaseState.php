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

final readonly class AppDatabaseState
{
    public function __construct(private EnvironmentFile $environment) {}

    public function connected(Application $application, bool $redis): ?ManagedDatabase
    {
        return $application->databases()
            ->when(
                $redis,
                fn($query) => $query->where('type', DatabaseType::Redis->value),
                fn($query) => $query->whereIn('type', [DatabaseType::Postgresql->value, DatabaseType::Mysql->value]),
            )
            ->orderBy('name')
            ->first();
    }

    public function database(Application $application): string
    {
        $database = $this->connected($application, false);

        if ($database !== null) {
            return 'existing:' . $database->getKey();
        }

        $connection = strtolower(trim($this->environment->parse((string) $application->env_variables)['DB_CONNECTION'] ?? ''));

        return strtolower((string) $application->framework) === 'laravel' && $connection === 'sqlite' ? 'sqlite' : 'none';
    }

    public function redis(Application $application): string
    {
        $redis = $this->connected($application, true);

        return $redis === null ? 'none' : 'existing:' . $redis->getKey();
    }

    public function horizon(Application $application): bool
    {
        return (((array) (((array) $application->stack)['packages'] ?? []))['horizon'] ?? false) === true;
    }
}
