<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Caddy;

use App\Exceptions\SSHCommandFailedException;
use App\Services\SSH\SSHConnection;
use Illuminate\Support\Arr;
use Illuminate\Support\Sleep;
use RuntimeException;
use stdClass;

final class CaddyAdminSocket
{
    public const LOAD_FILE = '/etc/caddy/caddy-config.socket.json';

    public const ATTEMPTS = 5;

    public function __construct(private readonly CaddyInstaller $installer) {}

    public function move(SSHConnection $connection): void
    {
        $this->installer->prepareAdminSocket($connection);

        $loaded = !$this->answers($connection, 1);

        if ($loaded) {
            $this->load($connection);
        }

        try {
            $connection->execute('sh -c ' . escapeshellarg(CaddyApiClient::persistScript(true)), sudo: true)->throw();
        } catch (SSHCommandFailedException $e) {
            if ($loaded) {
                $connection->execute('systemctl restart caddy', sudo: true);
            }

            throw $e;
        }

        $server = $connection->server();
        $server->forceFill(['meta' => [...Arr::except((array) $server->meta, 'caddy_admin_error'), 'caddy_admin' => 'socket']])->save();
    }

    private function load(SSHConnection $connection): void
    {
        $running = $connection->execute('curl -fsS --max-time 30 -X GET ' . CaddyApiClient::address(false, '/config/'), sudo: true)->throw()->output;

        $connection->uploadContent($this->onSocket($running), self::LOAD_FILE);

        try {
            $connection->execute(
                'curl -fsS --max-time 30 -X POST ' . CaddyApiClient::address(false, '/load') . ' -H ' . escapeshellarg('Content-Type: application/json') . ' --data-binary @' . self::LOAD_FILE,
                sudo: true,
            )->throw();
        } finally {
            $connection->execute('rm -f ' . self::LOAD_FILE, sudo: true);
        }

        if (!$this->answers($connection, self::ATTEMPTS)) {
            $connection->execute('systemctl restart caddy', sudo: true);

            throw new RuntimeException('Caddy took the new settings but never answered on its socket, so Coresmith restarted it on its saved ones.');
        }
    }

    private function answers(SSHConnection $connection, int $attempts): bool
    {
        for ($attempt = 1; ; $attempt++) {
            if ($connection->execute(CaddyApiClient::probeAt(true), sudo: true)->successful()) {
                return true;
            }

            if ($attempt >= $attempts) {
                return false;
            }

            Sleep::for(1)->second();
        }
    }

    private function onSocket(string $running): string
    {
        $config = json_decode($running, false, 512, JSON_THROW_ON_ERROR);

        if (!$config instanceof stdClass) {
            throw new RuntimeException("Caddy's running settings weren't a JSON object.");
        }

        $config->admin = (object) ['listen' => CaddyApiClient::ADMIN_LISTEN];

        return json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    }
}
