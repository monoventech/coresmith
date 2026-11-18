<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Database;

use App\Enums\Ability;
use App\Enums\DatabaseType;
use App\Enums\ServerStatus;
use App\Models\Application;
use App\Models\ManagedDatabase;
use App\Models\Server;
use App\Models\User;
use App\Services\Deployment\EnvironmentFile;
use App\Services\Deployment\StartingEnvironment;
use Illuminate\Support\Collection;

final readonly class ExpectedDatabases
{
    private const LOCAL = ['127.0.0.1', 'localhost', '::1'];

    private const ENGINES = ['postgresql' => 'PostgreSQL', 'mysql' => 'MySQL', 'redis' => 'Redis'];

    private const SCHEMES = [
        'postgres' => DatabaseType::Postgresql,
        'postgresql' => DatabaseType::Postgresql,
        'pgsql' => DatabaseType::Postgresql,
        'mysql' => DatabaseType::Mysql,
        'mysql2' => DatabaseType::Mysql,
        'mariadb' => DatabaseType::Mysql,
        'redis' => DatabaseType::Redis,
    ];

    public function __construct(private EnvironmentFile $environment, private SharedRedis $shared) {}

    /**
     * @return list<array{type: DatabaseType, host: string, port: int, name: ?string, problem: 'engine'|'port'|'name'}>
     */
    public function missing(Application $application): array
    {
        $server = $application->server;

        if ($server === null || $application->current_release !== null) {
            return [];
        }

        $variables = $this->environment->parse((string) $application->env_variables);
        $connected = $application->databases()->get()->map(fn(ManagedDatabase $database): ?DatabaseType => $database->type)->all();
        $missing = [];

        foreach (array_filter([$this->sql($variables), $this->redis($variables)]) as $expected) {
            if (!$this->local($expected['host'], (string) $server->ip_address) || in_array($expected['type'], $connected, true)) {
                continue;
            }

            $problem = $this->problem($server, $expected, ManagedDatabase::query()->where('server_id', $server->getKey())->where('type', $expected['type']->value)->where('status', '!=', ManagedDatabase::FAILED)->get());

            if ($problem !== null) {
                $missing[] = [...$expected, 'problem' => $problem];
            }
        }

        return $missing;
    }

    /**
     * @return list<array{key: string, lead: string, detail: string, field: string, create: array{value: string, label: string}|null, existing: list<array{value: string, label: string}>}>
     */
    public function banners(Application $application, User $user): array
    {
        $server = $application->server;

        if ($server === null) {
            return [];
        }

        $manages = $user->can('manageEnvironment', $application);
        $creates = $manages && $server->status === ServerStatus::Active && $user->hasTeamAbility($server->team, Ability::CreateDatabase);

        return array_map(function (array $missing) use ($application, $server, $user, $manages, $creates): array {
            $redis = $missing['type'] === DatabaseType::Redis;
            $engine = self::ENGINES[$missing['type']->value];
            $field = $redis ? 'redis' : 'database';
            $existing = !$manages ? [] : ManagedDatabase::query()->where('server_id', $server->getKey())->where('type', $missing['type']->value)->where('status', '!=', ManagedDatabase::FAILED)->orderBy('name')->get()
                ->filter(fn(ManagedDatabase $database): bool => $user->can('viewCredentials', $database))
                ->map(fn(ManagedDatabase $database): array => ['value' => 'existing:' . $database->getKey(), 'label' => (string) $database->name])
                ->values()->all();

            return [
                'key' => $missing['type']->value,
                'lead' => match ($missing['problem']) {
                    'engine' => "Coresmith hasn't set up {$engine} on {$server->name}.",
                    'port' => $redis ? "Coresmith doesn't run a Redis on port {$missing['port']} on {$server->name}." : "{$application->name}'s variables expect {$engine} on port {$missing['port']}.",
                    'name' => "{$server->name} has no {$engine} database called {$missing['name']}.",
                },
                'detail' => match (true) {
                    $missing['problem'] === 'engine' => "{$application->name}'s variables expect it at {$missing['host']}:{$missing['port']}, so a deploy can't connect.",
                    $missing['problem'] === 'port' && !$redis => "Coresmith runs it on {$this->port('', $missing['type'])} on {$server->name}, so a deploy can't connect.",
                    $redis => "{$application->name}'s variables expect one there, so a deploy can't connect.",
                    default => "{$application->name}'s variables expect it, so a deploy can't connect.",
                },
                'field' => $field,
                'create' => $creates ? ['value' => $redis ? 'new' : 'new:' . $missing['type']->value, 'label' => "Create a {$engine} database for {$application->name}"] : null,
                'existing' => $existing,
            ];
        }, $this->missing($application));
    }

    /**
     * @param  array{type: DatabaseType, host: string, port: int, name: ?string}  $expected
     * @param  Collection<int, ManagedDatabase>  $managed
     * @return 'engine'|'port'|'name'|null
     */
    private function problem(Server $server, array $expected, Collection $managed): ?string
    {
        $onPort = $managed->filter(fn(ManagedDatabase $database): bool => (int) $database->port === $expected['port']);

        if ($expected['type'] === DatabaseType::Redis) {
            return $onPort->isNotEmpty() || ($expected['port'] === ManagedDatabase::SHARED_REDIS_PORT && $this->shared->state($server) !== null) ? null : 'port';
        }

        return match (true) {
            $managed->isEmpty() => 'engine',
            $onPort->isEmpty() => 'port',
            $expected['name'] !== null && !$onPort->contains(fn(ManagedDatabase $database): bool => $database->name === $expected['name']) => 'name',
            default => null,
        };
    }

    /**
     * @param  array<string, string>  $variables
     * @return array{type: DatabaseType, host: string, port: int, name: ?string}|null
     */
    private function sql(array $variables): ?array
    {
        $type = match (strtolower(trim($variables['DB_CONNECTION'] ?? ''))) {
            'pgsql', 'postgres', 'postgresql' => DatabaseType::Postgresql,
            'mysql', 'mariadb' => DatabaseType::Mysql,
            default => null,
        };

        if ($type !== null) {
            return ['type' => $type, 'host' => trim($variables['DB_HOST'] ?? '') ?: '127.0.0.1', 'port' => $this->port($variables['DB_PORT'] ?? '', $type), 'name' => trim($variables['DB_DATABASE'] ?? '') ?: null];
        }

        foreach (['DATABASE_URL', 'SPRING_DATASOURCE_URL'] as $key) {
            $url = $this->url((string) preg_replace('/^jdbc:/', '', trim($variables[$key] ?? '')));

            if ($url !== null && $url['type'] !== DatabaseType::Redis) {
                return $url;
            }
        }

        return null;
    }

    /**
     * @param  array<string, string>  $variables
     * @return array{type: DatabaseType, host: string, port: int, name: ?string}|null
     */
    private function redis(array $variables): ?array
    {
        $url = $this->url(trim($variables['REDIS_URL'] ?? ''));

        if ($url !== null && $url['type'] === DatabaseType::Redis) {
            return $url;
        }

        foreach (StartingEnvironment::REDIS_SETTINGS as $setting) {
            if (strtolower(trim($variables[$setting] ?? '')) === 'redis') {
                return ['type' => DatabaseType::Redis, 'host' => trim($variables['REDIS_HOST'] ?? '') ?: '127.0.0.1', 'port' => $this->port($variables['REDIS_PORT'] ?? '', DatabaseType::Redis), 'name' => null];
            }
        }

        return null;
    }

    /**
     * @return array{type: DatabaseType, host: string, port: int, name: ?string}|null
     */
    private function url(string $value): ?array
    {
        $parts = parse_url($value);
        $type = is_array($parts) ? (self::SCHEMES[strtolower($parts['scheme'] ?? '')] ?? null) : null;

        if (!is_array($parts) || $type === null || !isset($parts['host'])) {
            return null;
        }

        return [
            'type' => $type,
            'host' => $parts['host'],
            'port' => $parts['port'] ?? $this->port('', $type),
            'name' => $type === DatabaseType::Redis ? null : (trim(rawurldecode($parts['path'] ?? ''), '/') ?: null),
        ];
    }

    private function port(string $value, DatabaseType $type): int
    {
        $value = trim($value);

        return ctype_digit($value) ? (int) $value : match ($type) {
            DatabaseType::Postgresql => 5432,
            DatabaseType::Mysql => 3306,
            DatabaseType::Redis => ManagedDatabase::SHARED_REDIS_PORT,
        };
    }

    private function local(string $host, string $address): bool
    {
        $host = strtolower(trim($host, '[] '));

        return in_array($host, self::LOCAL, true) || ($address !== '' && $host === strtolower(trim($address, '[] ')));
    }
}
