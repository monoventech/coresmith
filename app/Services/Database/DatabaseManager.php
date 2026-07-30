<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Database;

use App\Enums\DatabaseType;
use App\Enums\PrivilegeLevel;
use App\Models\ManagedDatabase;
use App\Services\Server\PackageManagerFactory;
use App\Services\SSH\SSHConnection;
use Illuminate\Support\Str;

final class DatabaseManager
{
    public const PASSWORD_LENGTH = 32;

    public const BACKUP_ROOT = '/var/coresmith/backups';

    public function __construct(
        private readonly MysqlDriver $mysql,
        private readonly PostgresDriver $postgres,
    ) {}

    public static function generatePassword(): string
    {
        return Str::password(self::PASSWORD_LENGTH, symbols: false);
    }

    public function ensureServerInstalled(SSHConnection $connection, DatabaseType $type): void
    {
        $packages = PackageManagerFactory::for($connection->server());
        $package = $this->packageFor($connection->server()->os_name, $type);

        $connection->execute(
            $packages->isInstalled($package).' || '.$packages->install([$package]),
            sudo: true,
        )->throw();

        $connection->execute($this->bindCommand($type), sudo: true)->throw();
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
        $directory = self::BACKUP_ROOT.'/'.$name;
        $destination = $directory.'/'.$name.'-'.$timestamp.'.sql.gz';

        $connection->execute('mkdir -p '.escapeshellarg($directory), sudo: true)->throw();

        $command = match ($database->type) {
            DatabaseType::Mysql => $this->mysql->backup($name, $destination),
            DatabaseType::Postgresql => $this->postgres->backup($name, $destination),
            DatabaseType::Redis => null,
        };

        if ($command !== null) {
            $connection->execute($command, sudo: true)->throw();
            $connection->execute('chmod 600 '.escapeshellarg($destination), sudo: true)->throw();
        }

        return $destination;
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
