<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Database;

use App\Services\SSH\SSHConnection;
use Illuminate\Support\Sleep;
use RuntimeException;

final class RedisInstance
{
    public const FIRST_PORT = 6380;

    public const LAST_PORT = 6479;

    public const CONFIG_DIR = '/etc/coresmith-redis';

    public const DATA_DIR = '/var/lib/coresmith-redis';

    public const ATTEMPTS = 10;

    public static function user(int $port): string
    {
        return "coresmith-redis-{$port}";
    }

    public static function unit(int $port): string
    {
        return self::user($port) . '.service';
    }

    public static function configPath(int $port): string
    {
        return self::CONFIG_DIR . "/{$port}.conf";
    }

    public static function dataPath(int $port): string
    {
        return self::DATA_DIR . "/{$port}";
    }

    public function create(SSHConnection $connection, int $port, #[\SensitiveParameter] string $password): void
    {
        $user = self::user($port);

        $connection->execute("id -u {$user} >/dev/null 2>&1 || useradd --system --no-create-home --home-dir /nonexistent --shell /usr/sbin/nologin --user-group {$user}", sudo: true)->throw();
        $connection->execute('install -d -m 0755 ' . self::CONFIG_DIR . ' ' . self::DATA_DIR . " && install -d -m 0700 -o {$user} -g {$user} " . self::dataPath($port), sudo: true)->throw();
        $connection->execute("install -m 0640 -o root -g {$user} /dev/null " . self::configPath($port), sudo: true)->throw();
        $connection->uploadContent($this->config($port, $password), self::configPath($port));
        $connection->uploadContent($this->unitFile($port), self::unitPath($port));
        $connection->execute('systemctl daemon-reload && systemctl enable ' . self::unit($port) . ' && systemctl restart ' . self::unit($port), sudo: true)->throw();

        $this->assertAnswers($connection, $port, $password);
    }

    public function changePassword(SSHConnection $connection, int $port, #[\SensitiveParameter] string $password): void
    {
        $connection->uploadContent($this->config($port, $password), self::configPath($port));
        $connection->execute('systemctl restart ' . self::unit($port), sudo: true)->throw();

        $this->assertAnswers($connection, $port, $password);
    }

    public function drop(SSHConnection $connection, int $port): void
    {
        $user = self::user($port);

        $connection->execute('systemctl disable --now ' . self::unit($port) . ' || true', sudo: true);
        $connection->execute('rm -f ' . self::unitPath($port) . ' ' . self::configPath($port) . ' && rm -rf ' . self::dataPath($port) . ' && systemctl daemon-reload', sudo: true)->throw();
        $connection->execute("if id -u {$user} >/dev/null 2>&1; then userdel {$user}; fi", sudo: true)->throw();
    }

    private function config(int $port, #[\SensitiveParameter] string $password): string
    {
        return implode("\n", [
            'bind 127.0.0.1',
            "port {$port}",
            'protected-mode yes',
            "requirepass {$password}",
            'dir ' . self::dataPath($port),
            'daemonize no',
            'logfile ""',
        ]) . "\n";
    }

    private function unitFile(int $port): string
    {
        $user = self::user($port);
        $config = self::configPath($port);
        $data = self::dataPath($port);

        return <<<UNIT
        [Unit]
        Description=Coresmith Redis on port {$port}
        After=network.target

        [Service]
        Type=simple
        User={$user}
        Group={$user}
        ExecStart=/usr/bin/redis-server {$config}
        Restart=always
        RestartSec=2
        LimitNOFILE=10032
        UMask=0077
        NoNewPrivileges=true
        ProtectSystem=strict
        ProtectHome=true
        PrivateTmp=true
        PrivateDevices=true
        ProtectKernelTunables=true
        ProtectKernelModules=true
        ProtectControlGroups=true
        RestrictAddressFamilies=AF_INET AF_INET6 AF_UNIX
        ReadWritePaths={$data}

        [Install]
        WantedBy=multi-user.target

        UNIT;
    }

    private static function unitPath(int $port): string
    {
        return '/etc/systemd/system/' . self::unit($port);
    }

    private function assertAnswers(SSHConnection $connection, int $port, #[\SensitiveParameter] string $password): void
    {
        $ping = 'sh -c ' . escapeshellarg("REDISCLI_AUTH=\"\$(cat)\" exec redis-cli -p {$port} ping");

        for ($attempt = 1; $attempt <= self::ATTEMPTS; $attempt++) {
            if (trim($connection->executeWithInput($ping, $password, sudo: true)->output) === 'PONG') {
                return;
            }

            Sleep::for(1)->second();
        }

        throw new RuntimeException("Redis on port {$port} didn't answer after it started. Its log is in journalctl -u " . self::user($port) . '.');
    }
}
