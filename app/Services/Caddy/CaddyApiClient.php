<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Caddy;

use App\Exceptions\SSHCommandFailedException;
use App\Models\Application;
use App\Models\Server;
use App\Services\SSH\SSHConnection;
use App\Services\SSH\SSHResult;
use Illuminate\Support\Sleep;

final class CaddyApiClient
{
    public const ADMIN_PORT = 2019;

    public const ADMIN_ORIGIN = 'http://localhost:' . self::ADMIN_PORT;

    public const ADMIN_SOCKET = '/run/caddy-admin/admin.sock';

    public const ADMIN_LISTEN = 'unix/' . self::ADMIN_SOCKET;

    public const ROUTES_PATH = '/config/apps/http/servers/' . CaddyInstaller::SERVER . '/routes';

    public const STAGED_CONFIG_FILE = '/etc/caddy/caddy-config.staged.json';

    public const RETRIES = 3;

    public const RETRY_SECONDS = 2;

    private const BUSY_EXIT_CODES = [7, 28, 52, 56];

    private const IDEMPOTENT_METHODS = ['GET', 'PUT', 'PATCH', 'DELETE'];

    public function __construct(
        private readonly CaddyRouteBuilder $builder,
        private readonly RouteConfigValidator $validator,
    ) {}

    public function addSite(SSHConnection $connection, Application $application): void
    {
        $config = $this->buildValidated($application);

        $this->request($connection, 'POST', self::ROUTES_PATH, $config);
        $this->persistConfig($connection);
    }

    public function updateSite(SSHConnection $connection, Application $application): void
    {
        $config = $this->buildValidated($application);

        $this->request($connection, 'PATCH', '/id/' . CaddyRouteBuilder::routeId($application), $config);
        $this->persistConfig($connection);
    }

    public function putSite(SSHConnection $connection, Application $application): void
    {
        $config = $this->buildValidated($application);
        $path = '/id/' . CaddyRouteBuilder::routeId($application);

        if ($this->exists($connection, $path)) {
            $this->request($connection, 'PATCH', $path, $config);
        } else {
            $this->request($connection, 'POST', self::ROUTES_PATH, $config);
        }

        $this->persistConfig($connection);
    }

    public function removeSite(SSHConnection $connection, Application $application): void
    {
        try {
            $this->request($connection, 'DELETE', '/id/' . CaddyRouteBuilder::routeId($application));
        } catch (SSHCommandFailedException $e) {
            if ($this->missing($e)) {
                return;
            }

            throw $e;
        }

        $this->persistConfig($connection);
    }

    public function persistConfig(SSHConnection $connection): void
    {
        $this->script($connection, self::persistScript($connection->server()->caddyAdminOnSocket()));
    }

    public static function persistScript(bool $socket): string
    {
        return 'curl -fsS --max-time 30 -X GET ' . self::address($socket, '/config/') . ' > ' . self::STAGED_CONFIG_FILE . ' && mv -f ' . self::STAGED_CONFIG_FILE . ' ' . CaddyInstaller::CONFIG_FILE;
    }

    private function script(SSHConnection $connection, string $script): void
    {
        $this->run($connection, 'sh -c ' . escapeshellarg($script));
    }

    private function exists(SSHConnection $connection, string $path): bool
    {
        try {
            $this->run($connection, $this->curl($connection->server(), 'GET', $path) . ' >/dev/null');

            return true;
        } catch (SSHCommandFailedException $e) {
            if ($this->missing($e)) {
                return false;
            }

            throw $e;
        }
    }

    private function missing(SSHCommandFailedException $e): bool
    {
        return str_contains($e->output(), 'returned error: 404');
    }

    private function run(SSHConnection $connection, string $command, bool $retry = true): SSHResult
    {
        for ($attempt = 0; ; $attempt++) {
            $result = $connection->execute($command, sudo: true);

            if ($result->successful() || !$retry || $attempt >= self::RETRIES || !$this->busy($result)) {
                return $result->throw();
            }

            Sleep::for(self::RETRY_SECONDS)->seconds();
        }
    }

    private function busy(SSHResult $result): bool
    {
        return in_array($result->exitCode, self::BUSY_EXIT_CODES, true)
            || preg_match('/returned error: 5\d\d/', $result->output) === 1;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildValidated(Application $application): array
    {
        $config = $this->builder->build($application);

        $this->validator->validate($config, $application);

        return $config;
    }

    public static function probe(Server $server, int $timeout = 10): string
    {
        return self::probeAt($server->caddyAdminOnSocket(), $timeout);
    }

    public static function probeAt(bool $socket, int $timeout = 10): string
    {
        return 'curl -fsS --max-time ' . $timeout . ' ' . self::address($socket, '/config/') . ' >/dev/null';
    }

    public static function address(bool $socket, string $path): string
    {
        if ($socket) {
            return '--unix-socket ' . self::ADMIN_SOCKET . ' ' . escapeshellarg('http://localhost' . $path);
        }

        return escapeshellarg(self::ADMIN_ORIGIN . $path) . ' -H ' . escapeshellarg('Origin: ' . self::ADMIN_ORIGIN);
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function request(SSHConnection $connection, string $method, string $path, ?array $payload = null): void
    {
        $this->run($connection, $this->curl($connection->server(), $method, $path, $payload), retry: in_array($method, self::IDEMPOTENT_METHODS, true));
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function curl(Server $server, string $method, string $path, ?array $payload = null): string
    {
        $command = 'curl -fsS --max-time 30 -X ' . $method . ' ' . self::address($server->caddyAdminOnSocket(), $path);

        if ($payload !== null) {
            $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $command .= ' -H ' . escapeshellarg('Content-Type: application/json') . ' -d ' . escapeshellarg($json);
        }

        return $command;
    }
}
