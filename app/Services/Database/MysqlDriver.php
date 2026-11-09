<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Database;

use App\Enums\PrivilegeLevel;
use App\Services\SafeName;

final class MysqlDriver
{
    public const PORT = 3306;

    public function createDatabase(string $name): string
    {
        $name = SafeName::assertDatabaseIdentifier($name);

        return "CREATE DATABASE IF NOT EXISTS `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;";
    }

    public function createUser(string $user, string $password): string
    {
        $user = SafeName::assertDatabaseIdentifier($user);
        $escaped = $this->quoteLiteral($password);

        return "CREATE USER IF NOT EXISTS '{$user}'@'localhost' IDENTIFIED BY {$escaped};";
    }

    public function changePassword(string $user, #[\SensitiveParameter] string $password): string
    {
        $user = SafeName::assertDatabaseIdentifier($user);

        return "ALTER USER '{$user}'@'localhost' IDENTIFIED BY {$this->quoteLiteral($password)};";
    }

    public function grant(string $database, string $user, PrivilegeLevel $level): string
    {
        $database = SafeName::assertDatabaseIdentifier($database);
        $user = SafeName::assertDatabaseIdentifier($user);
        $grants = implode(', ', $level->mysqlGrants());

        return "GRANT {$grants} ON `{$database}`.* TO '{$user}'@'localhost'; FLUSH PRIVILEGES;";
    }

    public function revokeAll(string $database, string $user): string
    {
        $database = SafeName::assertDatabaseIdentifier($database);
        $user = SafeName::assertDatabaseIdentifier($user);

        return "REVOKE ALL PRIVILEGES ON `{$database}`.* FROM '{$user}'@'localhost'; FLUSH PRIVILEGES;";
    }

    public function dropDatabase(string $name): string
    {
        $name = SafeName::assertDatabaseIdentifier($name);

        return "DROP DATABASE IF EXISTS `{$name}`;";
    }

    public function dropUser(string $user): string
    {
        $user = SafeName::assertDatabaseIdentifier($user);

        return "DROP USER IF EXISTS '{$user}'@'localhost';";
    }

    public function backup(string $database, string $destination): string
    {
        $database = SafeName::assertDatabaseIdentifier($database);

        return 'mysqldump --single-transaction --quick --routines --triggers '
            . escapeshellarg($database) . ' | gzip > ' . escapeshellarg($destination);
    }

    public function restore(string $database, string $source): string
    {
        $database = SafeName::assertDatabaseIdentifier($database);

        return 'gunzip -c ' . escapeshellarg($source) . ' | mysql ' . escapeshellarg($database);
    }

    public function bindToLoopback(): string
    {
        return "printf '[mysqld]\\nbind-address = 127.0.0.1\\n' > /etc/mysql/conf.d/coresmith-bind.cnf && systemctl restart mysql";
    }

    public function command(string $sql): string
    {
        return 'mysql --protocol=socket -e ' . escapeshellarg($sql);
    }

    private function quoteLiteral(string $value): string
    {
        return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $value) . "'";
    }
}
