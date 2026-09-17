<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Database;

use App\Enums\DatabaseType;
use App\Enums\PrivilegeLevel;
use App\Exceptions\BackupNotFoundException;
use App\Exceptions\ExistingDatabaseServerRefused;
use App\Models\ManagedDatabase;
use App\Services\Server\PackageManagerFactory;
use App\Services\SSH\SSHConnection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class DatabaseManager
{
    public const PASSWORD_LENGTH = 32;

    public const BACKUP_ROOT = '/var/coresmith/backups';

    private const SERVICES = [
        'mysql' => ['mysql', 'mysqld', 'mariadb'],
        'postgresql' => ['postgresql'],
        'redis' => ['redis-server', 'redis'],
    ];

    public function __construct(
        private readonly MysqlDriver $mysql,
        private readonly PostgresDriver $postgres,
    ) {}

    public static function generatePassword(): string
    {
        return Str::password(self::PASSWORD_LENGTH, symbols: false);
    }

    public function ensureServerInstalled(SSHConnection $connection, DatabaseType $type, ?ManagedDatabase $for = null): bool
    {
        $server = $connection->server();
        $meta = (array) $server->meta;
        $installed = array_values((array) ($meta['installed_databases'] ?? []));
        $managed = in_array($type->value, $installed, true)
            || ManagedDatabase::query()
                ->where('server_id', $server->getKey())
                ->where('type', $type->value)
                ->where('is_external', false)
                ->when($for !== null, fn($query) => $query->whereKeyNot($for->getKey()))
                ->exists();

        if (!$managed && $this->alreadyRunning($connection, $type)) {
            $this->assertCanAdminister($connection, $type);

            return true;
        }

        $packages = PackageManagerFactory::for($server);
        $package = $this->packageFor($server->os_name, $type);

        $connection->execute($packages->isInstalled($package) . ' || ' . $packages->install([$package]), sudo: true)->throw();
        $connection->execute($this->bindCommand($type), sudo: true)->throw();

        if (!in_array($type->value, $installed, true)) {
            $server->forceFill(['meta' => [...$meta, 'installed_databases' => [...$installed, $type->value]]])->save();
        }

        return false;
    }

    private function alreadyRunning(SSHConnection $connection, DatabaseType $type): bool
    {
        $checks = array_map(fn(string $service): string => 'systemctl is-active --quiet ' . escapeshellarg($service), self::SERVICES[$type->value]);

        return $connection->execute(implode(' || ', $checks))->successful();
    }

    private function assertCanAdminister(SSHConnection $connection, DatabaseType $type): void
    {
        $server = $connection->server()->name;

        [$command, $engine, $problem, $advice, $expected] = match ($type) {
            DatabaseType::Mysql => [$this->mysql->command('SELECT 1'), 'MySQL', 'Coresmith cannot create databases on it', 'Let root sign in through the local socket, or grant it CREATE, CREATE USER and GRANT OPTION, then try again.', null],
            DatabaseType::Postgresql => [$this->postgres->command('SELECT 1'), 'PostgreSQL', 'Coresmith cannot create databases on it', 'Make sure the postgres system user can run psql, then try again.', null],
            DatabaseType::Redis => ['redis-cli -t 3 ping 2>&1', 'Redis', 'Coresmith cannot reach it', 'Let redis-cli connect on this server without a password, or drop the requirepass setting, then try again.', 'PONG'],
        };

        $result = $connection->execute($command, sudo: true);

        if ($result->successful() && ($expected === null || str_contains($result->output, $expected))) {
            return;
        }

        if (str_contains($result->output, 'command not found')) {
            $problem = 'Coresmith cannot check it';
            $advice = 'Install redis-tools on the server so Coresmith can check it, then try again.';
        }

        throw new ExistingDatabaseServerRefused("{$engine} is already running on {$server}, but {$problem}. {$advice}");
    }

    public function create(SSHConnection $connection, ManagedDatabase $database, string $password): void
    {
        $level = PrivilegeLevel::tryFrom((string) $database->privilege_level) ?? PrivilegeLevel::Standard;
        $name = (string) $database->name;
        $user = (string) $database->db_user;

        match ($database->type) {
            DatabaseType::Mysql => $this->createMysql($connection, $name, $user, $password, $level),
            DatabaseType::Postgresql => $this->createPostgres($connection, $name, $user, $password, $level, $database),
            DatabaseType::Redis => null,
        };
    }

    public function drop(SSHConnection $connection, ManagedDatabase $database): void
    {
        $name = (string) $database->name;
        $user = (string) $database->db_user;

        match ($database->type) {
            DatabaseType::Mysql => $this->run($connection, [
                $this->mysql->command($this->mysql->dropDatabase($name)),
                $this->mysql->command($this->mysql->dropUser($user)),
            ]),
            DatabaseType::Postgresql => $this->run($connection, [
                $this->postgres->command($this->postgres->dropDatabase($name)),
                $this->postgres->command($this->postgres->dropUser($user)),
            ]),
            DatabaseType::Redis => null,
        };
    }

    public function backup(SSHConnection $connection, ManagedDatabase $database, string $timestamp): string
    {
        $name = (string) $database->name;
        $directory = self::BACKUP_ROOT . '/' . $name;
        $destination = $directory . '/' . $name . '-' . $timestamp . '.sql.gz';

        $connection->execute('mkdir -p ' . escapeshellarg($directory), sudo: true)->throw();

        $command = match ($database->type) {
            DatabaseType::Mysql => $this->mysql->backup($name, $destination),
            DatabaseType::Postgresql => $this->postgres->backup($name, $destination),
            DatabaseType::Redis => null,
        };

        if ($command !== null) {
            $connection->execute($command, sudo: true)->throw();
            $connection->execute('chmod 600 ' . escapeshellarg($destination), sudo: true)->throw();
        }

        return $destination;
    }

    public function restore(SSHConnection $connection, ManagedDatabase $database, string $source): void
    {
        $name = (string) $database->name;

        $exists = $connection->execute('test -f ' . escapeshellarg($source));

        if ($exists->failed()) {
            throw BackupNotFoundException::at($source);
        }

        $command = match ($database->type) {
            DatabaseType::Mysql => $this->mysql->restore($name, $source),
            DatabaseType::Postgresql => $this->postgres->restore($name, $source),
            DatabaseType::Redis => null,
        };

        if ($command !== null) {
            $connection->execute($command, sudo: true)->throw();
        }
    }

    public function uploadBackupToS3(SSHConnection $connection, ManagedDatabase $database, string $remotePath): string
    {
        $disk = Storage::disk('s3');
        $contents = $connection->execute('cat ' . escapeshellarg($remotePath), sudo: true)->throw()->output;
        $key = 'backups/' . $database->getKey() . '/' . basename($remotePath);

        $disk->put($key, $contents);

        return $key;
    }

    private function createMysql(SSHConnection $connection, string $name, string $user, string $password, PrivilegeLevel $level): void
    {
        $this->run($connection, [
            $this->mysql->command($this->mysql->createDatabase($name)),
            $this->mysql->command($this->mysql->createUser($user, $password)),
            $this->mysql->command($this->mysql->grant($name, $user, $level)),
        ]);
    }

    private function createPostgres(SSHConnection $connection, string $name, string $user, string $password, PrivilegeLevel $level, ManagedDatabase $database): void
    {
        $this->run($connection, [
            $this->postgres->command($this->postgres->createUser($user, $password)),
            $this->postgres->command($this->postgres->createDatabase($name, $user)),
            $this->postgres->command($this->postgres->grant($name, $user, $level), $name),
        ]);

        foreach ((array) $database->extensions as $extension) {
            $connection->execute($this->postgres->command($this->postgres->createExtension((string) $extension, $name), $name), sudo: true)->throw();
        }
    }

    /**
     * @param  list<string>  $commands
     */
    private function run(SSHConnection $connection, array $commands): void
    {
        foreach ($commands as $command) {
            $connection->execute($command, sudo: true)->throw();
        }
    }

    private function bindCommand(DatabaseType $type): string
    {
        return match ($type) {
            DatabaseType::Mysql => $this->mysql->bindToLoopback(),
            DatabaseType::Postgresql => $this->postgres->bindToLoopback(),
            DatabaseType::Redis => "sed -i 's/^bind .*/bind 127.0.0.1/' /etc/redis/redis.conf && systemctl restart redis-server || systemctl restart redis",
        };
    }

    private function packageFor(?string $osName, DatabaseType $type): string
    {
        $debian = in_array($osName, ['ubuntu', 'debian'], true);

        return match ($type) {
            DatabaseType::Mysql => $debian ? 'mysql-server' : 'mysql-server',
            DatabaseType::Postgresql => $debian ? 'postgresql' : 'postgresql-server',
            DatabaseType::Redis => $debian ? 'redis-server' : 'redis',
        };
    }
}
