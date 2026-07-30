<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Database;

use App\Enums\PrivilegeLevel;
use App\Services\SafeName;

final class PostgresDriver
{
    public const PORT = 5432;

    public function createDatabase(string $name, string $owner): string
    {
        $name = SafeName::assertDatabaseIdentifier($name);
        $owner = SafeName::assertDatabaseIdentifier($owner);

        return "CREATE DATABASE \"{$name}\" OWNER \"{$owner}\";";
    }

    public function createUser(string $user, string $password): string
    {
        $user = SafeName::assertDatabaseIdentifier($user);
        $escaped = $this->quoteLiteral($password);

        return "CREATE ROLE \"{$user}\" LOGIN PASSWORD {$escaped} NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT;";
    }

    public function grant(string $database, string $user, PrivilegeLevel $level): string
    {
        $database = SafeName::assertDatabaseIdentifier($database);
        $user = SafeName::assertDatabaseIdentifier($user);

        $statements = [
            "GRANT CONNECT ON DATABASE \"{$database}\" TO \"{$user}\";",
            "GRANT USAGE, CREATE ON SCHEMA public TO \"{$user}\";",
            "GRANT SELECT, INSERT, UPDATE, DELETE, REFERENCES, TRIGGER ON ALL TABLES IN SCHEMA public TO \"{$user}\";",
            "GRANT USAGE, SELECT, UPDATE ON ALL SEQUENCES IN SCHEMA public TO \"{$user}\";",
            "ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE, REFERENCES, TRIGGER ON TABLES TO \"{$user}\";",
            "ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT USAGE, SELECT, UPDATE ON SEQUENCES TO \"{$user}\";",
        ];

        if ($level !== PrivilegeLevel::Standard) {
            $statements[] = "GRANT EXECUTE ON ALL FUNCTIONS IN SCHEMA public TO \"{$user}\";";
            $statements[] = "ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT EXECUTE ON FUNCTIONS TO \"{$user}\";";
        }

        return implode(' ', $statements);
    }

    public function dropDatabase(string $name): string
    {
        $name = SafeName::assertDatabaseIdentifier($name);

        return "DROP DATABASE IF EXISTS \"{$name}\";";
    }

    public function dropUser(string $user): string
    {
        $user = SafeName::assertDatabaseIdentifier($user);

        return "DROP ROLE IF EXISTS \"{$user}\";";
    }

    public function createExtension(string $extension, string $database): string
    {
        $extension = SafeName::assertDatabaseIdentifier(str_replace('-', '_', $extension));
        $database = SafeName::assertDatabaseIdentifier($database);

        return "CREATE EXTENSION IF NOT EXISTS \"{$extension}\";";
    }

    public function backup(string $database, string $destination): string
    {
        $database = SafeName::assertDatabaseIdentifier($database);

        return 'sudo -u postgres pg_dump --no-owner --no-acl '
            .escapeshellarg($database).' | gzip > '.escapeshellarg($destination);
    }

    public function restore(string $database, string $source): string
    {
        $database = SafeName::assertDatabaseIdentifier($database);

        return 'gunzip -c '.escapeshellarg($source).' | sudo -u postgres psql '.escapeshellarg($database);
    }

    public function bindToLoopback(): string
    {
        return "sudo -u postgres psql -c \"ALTER SYSTEM SET listen_addresses = '127.0.0.1';\" && systemctl restart postgresql";
    }

    public function command(string $sql, ?string $database = null): string
    {
        $command = 'sudo -u postgres psql -v ON_ERROR_STOP=1';

        if ($database !== null) {
            $command .= ' -d '.escapeshellarg(SafeName::assertDatabaseIdentifier($database));
        }

        return $command.' -c '.escapeshellarg($sql);
    }

    private function quoteLiteral(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }
}
