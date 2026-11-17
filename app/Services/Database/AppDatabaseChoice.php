<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Database;

use App\Enums\Ability;
use App\Enums\DatabaseType;
use App\Enums\PrivilegeLevel;
use App\Enums\ServerStatus;
use App\Models\Application;
use App\Models\ManagedDatabase;
use App\Models\Server;
use App\Models\User;
use App\Services\Deployment\StackDefaults;

final readonly class AppDatabaseChoice
{
    public const DATABASE = '/^(sqlite|none|new:(postgresql|mysql)|existing:[0-9A-Za-z]{26})$/D';

    public const REDIS = '/^(none|new|existing:[0-9A-Za-z]{26})$/D';

    private const SQL = [DatabaseType::Postgresql, DatabaseType::Mysql];

    public function __construct(private DatabaseCreator $creator, private DatabaseConnections $connections, private StackDefaults $defaults) {}

    /**
     * @return array<string, string>
     */
    public function problems(Server $server, User $user, ?string $database, ?string $redis): array
    {
        $problems = [];

        foreach (['database' => [(string) $database, self::SQL], 'redis' => [(string) $redis, [DatabaseType::Redis]]] as $field => [$choice, $types]) {
            if (str_starts_with($choice, 'new') && ($server->status !== ServerStatus::Active || !$user->hasTeamAbility($server->team, Ability::CreateDatabase))) {
                $problems[$field] = $server->status === ServerStatus::Active
                    ? "Your role can't create databases. Pick one that's already on {$server->name}."
                    : "Coresmith can create a database once {$server->name} is ready. Pick another option for now.";
            }

            if (str_starts_with($choice, 'existing:')) {
                $existing = $this->existing($server, substr($choice, 9), $types);

                if ($existing === null || !$user->can('viewCredentials', $existing)) {
                    $problems[$field] = "That database isn't on {$server->name}. Pick another one.";
                }
            }
        }

        return $problems;
    }

    /**
     * @return list<ManagedDatabase>
     */
    public function apply(Application $application, ?string $database, ?string $redis): array
    {
        $database ??= 'none';
        $redis ??= 'none';

        if ($database === 'sqlite' && strtolower((string) $application->framework) === 'laravel') {
            $application->forceFill(['env_variables' => $this->defaults->fill((string) $application->env_variables, ['DB_CONNECTION' => 'sqlite'])])->save();
        }

        $chosen = array_values(array_filter([$this->database($application, $database), $this->redis($application, $redis)]));

        foreach ($chosen as $managed) {
            $this->connections->connect($application, $managed);
        }

        return $chosen;
    }

    private function database(Application $application, string $choice): ?ManagedDatabase
    {
        if (str_starts_with($choice, 'existing:')) {
            return $this->existing($application->server, substr($choice, 9), self::SQL);
        }

        if (!str_starts_with($choice, 'new:')) {
            return null;
        }

        $name = $this->creator->freeName($application->slug);

        return $this->creator->create($application->server, [
            'type' => DatabaseType::from(substr($choice, 4)),
            'name' => $name,
            'db_user' => $name,
            'privilege_level' => PrivilegeLevel::Standard->value,
            'project_id' => $application->project_id,
        ]);
    }

    private function redis(Application $application, string $choice): ?ManagedDatabase
    {
        if (str_starts_with($choice, 'existing:')) {
            return $this->existing($application->server, substr($choice, 9), [DatabaseType::Redis]);
        }

        return $choice === 'new'
            ? $this->creator->create($application->server, ['type' => DatabaseType::Redis, 'name' => $this->creator->freeName($application->slug . '_redis'), 'project_id' => $application->project_id])
            : null;
    }

    /**
     * @param  list<DatabaseType>  $types
     */
    private function existing(Server $server, string $id, array $types): ?ManagedDatabase
    {
        return ManagedDatabase::query()
            ->whereKey($id)
            ->where('server_id', $server->getKey())
            ->where('status', '!=', ManagedDatabase::FAILED)
            ->whereIn('type', array_map(fn(DatabaseType $type): string => $type->value, $types))
            ->first();
    }
}
